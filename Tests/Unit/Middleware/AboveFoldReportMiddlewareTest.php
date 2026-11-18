<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Middleware;

use Doctrine\DBAL\Result;
use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Middleware\AboveFoldReportMiddleware;
use Maispace\MaiAssets\Security\AboveFoldTokenService;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;

final class AboveFoldReportMiddlewareTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $cacheStore = [];

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'test-key';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = [
            'viewportBuckets' => ['mobile' => 768, 'tablet' => 1024, 'desktop' => 99999],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'], $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets']);
    }

    public function testElementsOfOtherPagesAreDroppedInsteadOfRejectingTheReport(): void
    {
        // 4 and 9 belong to page 1; 50 is a referenced footer element stored on another page.
        $response = $this->process(1, [4, 50, 9], uidsOfPage: [4, 9]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([4, 9], $this->cacheStore['desktop']);
    }

    public function testReportWithOnlyForeignElementsStillRegistersTheBucket(): void
    {
        $response = $this->process(1, [50], uidsOfPage: []);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->cacheStore['desktop']);
        self::assertSame(['desktop'], $this->cacheStore['buckets_1']);
    }

    /**
     * @param list<int> $criticalUids
     * @param list<int> $uidsOfPage UIDs the database reports as content of the page
     */
    private function process(int $pageUid, array $criticalUids, array $uidsOfPage): ResponseInterface
    {
        $tokenService = new AboveFoldTokenService();
        $body = (string) json_encode([
            'pageUid' => $pageUid,
            'resetTimestamp' => 1000,
            'token' => $tokenService->generate($pageUid, 1000),
            'bucket' => 'desktop',
            'criticalUids' => $criticalUids,
        ]);
        $request = (new ServerRequest('https://example.test/api/mai-assets/above-fold-report', 'POST'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream($body));

        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn($uidsOfPage);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'where'] as $method) {
            $queryBuilder->method($method)->willReturnSelf();
        }
        $queryBuilder->method('getRestrictions')->willReturn($this->createMock(QueryRestrictionContainerInterface::class));
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');
        $queryBuilder->method('expr')->willReturn($this->createMock(ExpressionBuilder::class));
        $queryBuilder->method('executeQuery')->willReturn($result);
        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($queryBuilder);
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        // Cache keys are "<page>_<bucket>"-like hashes; store by bucket name for readable assertions.
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend->method('get')->willReturnCallback(fn(string $key): mixed => $this->cacheStore[$key] ?? false);
        $cacheFrontend->method('set')->willReturnCallback(function (string $key, mixed $data): void {
            $this->cacheStore[str_starts_with($key, 'buckets_') ? $key : 'desktop'] = $data;
        });
        $aboveFoldCacheService = (new \ReflectionClass(AboveFoldCacheService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AboveFoldCacheService::class, 'cache'))->setValue($aboveFoldCacheService, $cacheFrontend);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnArgument(0);
        (new \ReflectionProperty(AboveFoldCacheService::class, 'eventDispatcher'))->setValue($aboveFoldCacheService, $eventDispatcher);

        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willThrowException(new \RuntimeException('no cache', 1763500011));

        $subject = new AboveFoldReportMiddleware(
            $aboveFoldCacheService,
            new ExtensionConfiguration((new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor()),
            $tokenService,
            $cacheManager,
            $connectionPool,
        );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        return $subject->process($request, $handler);
    }
}
