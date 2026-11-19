<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\DataProcessing;

use Doctrine\DBAL\Result;
use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\DataProcessing\CriticalAssetDataProcessor;
use Maispace\MaiAssets\Service\CriticalDetectionService;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

final class CriticalAssetDataProcessorTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets']);
    }

    public function testReferencedElementUsesObserverDataOfTheRenderedPage(): void
    {
        // Element 50 is stored on page 9 (footer storage) but shown on page 1,
        // where the observer reported it above the fold.
        $result = $this->process(renderedPage: 1, record: ['uid' => 50, 'pid' => 9], observedOnRenderedPage: [50]);

        self::assertTrue($result['isCritical']);
        self::assertSame('high', $result['fetchPriority']);
        self::assertSame('eager', $result['loadingStrategy']);
    }

    public function testElementNotReportedIsLazy(): void
    {
        $result = $this->process(renderedPage: 1, record: ['uid' => 51, 'pid' => 1], observedOnRenderedPage: [50]);

        self::assertFalse($result['isCritical']);
        self::assertSame('lazy', $result['loadingStrategy']);
    }

    /**
     * @param array{uid: int, pid: int} $record
     * @param list<int> $observedOnRenderedPage
     * @return array<string, mixed>
     */
    private function process(int $renderedPage, array $record, array $observedOnRenderedPage): array
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = ['criticalThresholdByColPos' => []];

        $dbResult = $this->createMock(Result::class);
        $dbResult->method('fetchAssociative')->willReturn($record + ['colPos' => 0, 'sorting' => 256, 'tx_maiassets_is_critical' => 0, 'tx_maiassets_force_critical' => 0]);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'setMaxResults'] as $method) {
            $queryBuilder->method($method)->willReturnSelf();
        }
        $queryBuilder->method('expr')->willReturn($this->createMock(ExpressionBuilder::class));
        $queryBuilder->method('executeQuery')->willReturn($dbResult);
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturnCallback(static fn(string $key): mixed => match ($key) {
            'buckets_' . $renderedPage => ['desktop'],
            'page_' . $renderedPage . '_desktop' => $observedOnRenderedPage,
            default => false,
        });
        $aboveFold = (new \ReflectionClass(AboveFoldCacheService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AboveFoldCacheService::class, 'cache'))->setValue($aboveFold, $cache);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $detection = new CriticalDetectionService(
            $aboveFold,
            new ExtensionConfiguration((new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor()),
            $dispatcher,
            $connectionPool,
        );

        $cObj = $this->createMock(ContentObjectRenderer::class);
        $cObj->method('getRequest')->willReturn(
            (new ServerRequest('https://example.test/'))->withAttribute('routing', new PageArguments($renderedPage, '0', [])),
        );

        return (new CriticalAssetDataProcessor($detection))->process($cObj, [], [], ['data' => $record]);
    }
}
