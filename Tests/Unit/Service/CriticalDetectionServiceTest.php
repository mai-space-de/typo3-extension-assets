<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Service\CriticalDetectionService;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class CriticalDetectionServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets']);
    }

    public function testDefaultValueOfTheOverrideMeansAutomatic(): void
    {
        // Regression: the former checkbox default 0 was read as "editor said not critical".
        self::assertNull(CriticalDetectionService::resolveEditorOverride([
            'tx_maiassets_force_critical' => 0,
            'tx_maiassets_is_critical' => 0,
        ]));
        self::assertNull(CriticalDetectionService::resolveEditorOverride([]));
    }

    public function testEditorCanForceBothDirections(): void
    {
        self::assertTrue(CriticalDetectionService::resolveEditorOverride(['tx_maiassets_is_critical' => 1]));
        self::assertFalse(CriticalDetectionService::resolveEditorOverride(['tx_maiassets_is_critical' => '2']));
    }

    public function testForceFlagWinsOverOverride(): void
    {
        self::assertTrue(CriticalDetectionService::resolveEditorOverride([
            'tx_maiassets_force_critical' => 1,
            'tx_maiassets_is_critical' => 2,
        ]));
    }

    public function testAutomaticElementIsCriticalWhenObserverReportedIt(): void
    {
        $subject = $this->createSubject(
            record: ['uid' => 7, 'pid' => 1, 'colPos' => 0, 'sorting' => 512, 'tx_maiassets_is_critical' => 0, 'tx_maiassets_force_critical' => 0],
            observedUids: [3, 7],
        );

        self::assertTrue($subject->isCritical(1, 7));
    }

    public function testAutomaticElementNotReportedAndWithoutThresholdIsNotCritical(): void
    {
        $subject = $this->createSubject(
            record: ['uid' => 9, 'pid' => 1, 'colPos' => 0, 'sorting' => 512, 'tx_maiassets_is_critical' => 0, 'tx_maiassets_force_critical' => 0],
            observedUids: [3, 7],
        );

        self::assertFalse($subject->isCritical(1, 9));
    }

    public function testEditorNoBeatsObserverData(): void
    {
        $subject = $this->createSubject(
            record: ['uid' => 7, 'pid' => 1, 'colPos' => 0, 'sorting' => 512, 'tx_maiassets_is_critical' => 2, 'tx_maiassets_force_critical' => 0],
            observedUids: [7],
        );

        self::assertFalse($subject->isCritical(1, 7));
    }

    /**
     * @param array<string, mixed> $record
     * @param list<int>            $observedUids critical UIDs reported for the "desktop" bucket
     */
    private function createSubject(array $record, array $observedUids): CriticalDetectionService
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn($record);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'setMaxResults'] as $method) {
            $queryBuilder->method($method)->willReturnSelf();
        }
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');
        $queryBuilder->method('expr')->willReturn($this->createMock(ExpressionBuilder::class));
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend->method('get')->willReturnCallback(
            static fn(string $key): mixed => match (true) {
                str_starts_with($key, 'buckets_') => ['desktop'],
                default => $observedUids,
            },
        );
        $aboveFoldCacheService = (new \ReflectionClass(AboveFoldCacheService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AboveFoldCacheService::class, 'cache'))->setValue($aboveFoldCacheService, $cacheFrontend);

        // No colPos thresholds: the position heuristic never marks anything critical.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = ['criticalThresholdByColPos' => []];
        $extensionConfiguration = new ExtensionConfiguration(
            (new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor(),
        );

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnArgument(0);

        return new CriticalDetectionService($aboveFoldCacheService, $extensionConfiguration, $eventDispatcher, $connectionPool);
    }
}
