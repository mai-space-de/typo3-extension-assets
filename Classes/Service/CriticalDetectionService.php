<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Event\ModifyCriticalThresholdEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class CriticalDetectionService
{
    public function __construct(
        private AboveFoldCacheService $aboveFoldCacheService,
        private ExtensionConfiguration $extensionConfiguration,
        private EventDispatcherInterface $eventDispatcher,
        private ConnectionPool $connectionPool,
    ) {}

    /** tx_maiassets_is_critical: decide from observer data and position heuristic. */
    public const int OVERRIDE_AUTO = 0;
    /** tx_maiassets_is_critical: editor marked the element as critical. */
    public const int OVERRIDE_CRITICAL = 1;
    /** tx_maiassets_is_critical: editor marked the element as not critical. */
    public const int OVERRIDE_NOT_CRITICAL = 2;

    public function isCritical(int $pageUid, int $elementUid): bool
    {
        $record = $this->getContentRecord($elementUid);
        if ($record === null) {
            return false;
        }

        // 1. + 2. Editor decisions (force flag, override select)
        $override = self::resolveEditorOverride($record);
        if ($override !== null) {
            return $override;
        }

        // 3. Cache service result (from observer reports)
        if (in_array($elementUid, $this->aboveFoldCacheService->getAllCriticalUids($pageUid), true)) {
            return true;
        }

        // 4. Fallback: position/sorting heuristic
        $colPos = (int) ($record['colPos'] ?? 0);
        $threshold = $this->getThresholdForColPos($colPos);
        if ($threshold <= 0) {
            return false;
        }

        // Count elements in same colPos with lower sorting (= higher on page)
        $position = $this->getElementPositionInColPos(
            $pageUid,
            $colPos,
            (int) ($record['sorting'] ?? 0),
            (int) ($record['sys_language_uid'] ?? 0),
        );
        return $position < $threshold;
    }

    /**
     * Editor decision stored on the content record; null means "decide automatically".
     *
     * @param array<string, mixed> $record
     */
    public static function resolveEditorOverride(array $record): ?bool
    {
        if ((bool) ($record['tx_maiassets_force_critical'] ?? false)) {
            return true;
        }

        return match ((int) ($record['tx_maiassets_is_critical'] ?? self::OVERRIDE_AUTO)) {
            self::OVERRIDE_CRITICAL => true,
            self::OVERRIDE_NOT_CRITICAL => false,
            default => null,
        };
    }

    public function getThresholdForColPos(int $colPos): int
    {
        $thresholds = $this->extensionConfiguration->getCriticalThresholdByColPos();
        $threshold = (int) ($thresholds[$colPos] ?? 0);

        $event = new ModifyCriticalThresholdEvent($threshold, $colPos, 0);
        $this->eventDispatcher->dispatch($event);

        return $event->getThreshold();
    }

    public function resolveBucketFromRequest(ServerRequestInterface $request): string
    {
        $cookies = $request->getCookieParams();
        $bucket = $cookies['viewport_bucket'] ?? '';

        $validBuckets = array_keys($this->extensionConfiguration->getViewportBuckets());
        if ($bucket !== '' && in_array($bucket, $validBuckets, true)) {
            return $bucket;
        }

        return 'desktop';
    }

    private function getContentRecord(int $elementUid): ?array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('tt_content');

        $result = $queryBuilder
            ->select('uid', 'pid', 'colPos', 'sorting', 'sys_language_uid', 'tx_maiassets_is_critical', 'tx_maiassets_force_critical')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($elementUid, Connection::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();

        $row = $result->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /**
     * Elements above the given one in the same column: only live records of the same
     * language count (translations and workspace versions share pid, colPos and sorting).
     */
    private function getElementPositionInColPos(int $pageUid, int $colPos, int $sorting, int $languageUid): int
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('tt_content');

        return (int) $queryBuilder
            ->count('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter($colPos, Connection::PARAM_INT)),
                $queryBuilder->expr()->lt('sorting', $queryBuilder->createNamedParameter($sorting, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
    }
}
