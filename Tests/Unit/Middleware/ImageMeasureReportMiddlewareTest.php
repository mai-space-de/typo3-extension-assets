<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Middleware;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementProfileResolver;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementRepository;
use Maispace\MaiAssets\Middleware\ImageMeasureReportMiddleware;
use Maispace\MaiAssets\Security\ImageMeasureTokenService;
use Maispace\MaiAssets\Service\ImageMeasureScriptBuilder;
use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;

final class ImageMeasureReportMiddlewareTest extends TestCase
{
    /** @var ImageMeasurementRepository&MockObject */
    private ImageMeasurementRepository $repository;
    /** @var ImageMeasurementProfileResolver&MockObject */
    private ImageMeasurementProfileResolver $profileResolver;
    /** @var CacheManager&MockObject */
    private CacheManager $cacheManager;
    /** @var StaticFileRemovalService&MockObject */
    private StaticFileRemovalService $staticFileRemovalService;

    /** @var list<string> */
    private array $flushedTags = [];
    /** @var list<int> */
    private array $purgedPages = [];

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'test-key';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = ['enableImageMeasurement' => true];

        $this->repository = $this->createMock(ImageMeasurementRepository::class);
        $this->profileResolver = $this->createMock(ImageMeasurementProfileResolver::class);
        $this->cacheManager = $this->createMock(CacheManager::class);
        $this->cacheManager->method('getCache')->willThrowException(new \RuntimeException('no cache', 1763500001));
        $this->cacheManager->method('flushCachesByTag')->willReturnCallback(function (string $tag): void {
            $this->flushedTags[] = $tag;
        });
        $this->staticFileRemovalService = $this->createMock(StaticFileRemovalService::class);
        $this->staticFileRemovalService->method('purgeByPageUid')->willReturnCallback(function (int $pageUid): void {
            $this->purgedPages[] = $pageUid;
        });
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'], $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets']);
    }

    public function testChangedSharedSlotRefreshesEveryPageThatRendersIt(): void
    {
        $this->repository->method('record')->willReturn(true);
        $this->repository->method('rememberPage')->willReturn(false);
        $this->repository->method('getPageUids')->with('s.textmedia-left')->willReturn([3, 7, 12]);
        $this->profileResolver->method('signature')->willReturnOnConsecutiveCalls('', 'new');

        $response = $this->process(12, 's.textmedia-left');

        self::assertSame(200, $response->getStatusCode());
        self::assertEqualsCanonicalizing(['pageId_3', 'pageId_7', 'pageId_12'], $this->flushedTags);
        self::assertEqualsCanonicalizing([3, 7, 12], $this->purgedPages);
    }

    public function testUnchangedProfileOnKnownPageRefreshesNothing(): void
    {
        $this->repository->method('record')->willReturn(true);
        $this->repository->method('rememberPage')->willReturn(false);
        $this->repository->expects(self::never())->method('getPageUids');
        $this->profileResolver->method('signature')->willReturn('same');

        $this->process(12, 's.textmedia-left');

        self::assertSame([], $this->flushedTags);
        self::assertSame([], $this->purgedPages);
    }

    public function testNewPageOfAnExistingProfileIsRefreshedOnce(): void
    {
        // The page may have been cached before the profile existed.
        $this->repository->method('record')->willReturn(false);
        $this->repository->method('rememberPage')->with('s.textmedia-left', 5)->willReturn(true);
        $this->profileResolver->method('signature')->willReturn('existing');

        $this->process(5, 's.textmedia-left');

        self::assertSame(['pageId_5'], $this->flushedTags);
        self::assertSame([5], $this->purgedPages);
    }

    public function testNewPageWithoutProfileIsNotRefreshed(): void
    {
        $this->repository->method('record')->willReturn(true);
        $this->repository->method('rememberPage')->willReturn(true);
        $this->profileResolver->method('signature')->willReturn('');

        $this->process(5, 's.textmedia-left');

        self::assertSame([], $this->flushedTags);
    }

    public function testMissingPageTableDoesNotBreakReporting(): void
    {
        $this->repository->method('record')->willReturn(true);
        $this->repository->method('rememberPage')->willThrowException(new \RuntimeException('table missing', 1763500002));
        $this->repository->method('getPageUids')->willThrowException(new \RuntimeException('table missing', 1763500003));
        $this->profileResolver->method('signature')->willReturnOnConsecutiveCalls('', 'new');

        $response = $this->process(12, 's.textmedia-left');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['pageId_12'], $this->flushedTags);
    }

    private function process(int $pageUid, string $key): ResponseInterface
    {
        $tokenService = new ImageMeasureTokenService();
        $body = (string) json_encode([
            'p' => $pageUid,
            'v' => 1280,
            'm' => [['k' => $key, 't' => $tokenService->generate($pageUid, $key), 'w' => 600]],
        ]);

        $request = (new ServerRequest('https://example.test' . ImageMeasureScriptBuilder::ENDPOINT, 'POST'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream($body));

        $subject = new ImageMeasureReportMiddleware(
            new ExtensionConfiguration((new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor()),
            $tokenService,
            $this->repository,
            $this->profileResolver,
            $this->cacheManager,
            $this->staticFileRemovalService,
        );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        return $subject->process($request, $handler);
    }
}
