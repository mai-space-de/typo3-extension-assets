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

    public function testPositionHeuristicCountsOnlyLiveElementsOfTheSameLanguage(): void
    {
        $subject = $this->createSubject(
            record: ['uid' => 30, 'pid' => 1, 'colPos' => 0, 'sorting' => 512, 'sys_language_uid' => 0, 'tx_maiassets_is_critical' => 0, 'tx_maiassets_force_critical' => 0],
            observedUids: [],
            thresholds: [0 => 2],
            elementsAbove: 1,
        );

        self::assertTrue($subject->isCritical(1, 30));
        self::assertContains(['sys_language_uid', ':dcValue1'], $this->comparisons);
        self::assertContains(['t3ver_wsid', ':dcValue1'], $this->comparisons);
    }

    /** @var list<array{0: string, 1: mixed}> */
    private array $comparisons = [];

    /**
     * @param array<string, mixed> $record
     * @param list<int>            $observedUids critical UIDs reported for the "desktop" bucket
     * @param array<int, int>      $thresholds   criticalThresholdByColPos
     */
    private function createSubject(array $record, array $observedUids, array $thresholds = [], int $elementsAbove = 0): CriticalDetectionService
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn($record);
        $result->method('fetchOne')->willReturn($elementsAbove);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'setMaxResults', 'count'] as $method) {
            $queryBuilder->method($method)->willReturnSelf();
        }
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');
        $expr = $this->createMock(ExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(function (string $field, mixed $value): string {
            $this->comparisons[] = [$field, $value];

            return $field . ' = ' . $value;
        });
        $queryBuilder->method('expr')->willReturn($expr);
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

        // Thresholds per colPos (none by default: the position heuristic marks nothing critical).
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = ['criticalThresholdByColPos' => $thresholds];
        $extensionConfiguration = new ExtensionConfiguration(
            (new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor(),
        );

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnArgument(0);

        return new CriticalDetectionService($aboveFoldCacheService, $extensionConfiguration, $eventDispatcher, $connectionPool);
    }
}
