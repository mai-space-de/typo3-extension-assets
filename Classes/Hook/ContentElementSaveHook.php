<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Hook;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ContentElementSaveHook
{
    private const POSITION_RELEVANT_FIELDS = [
        'sorting',
        'colPos',
        'pid',
        'hidden',
        'deleted',
        'starttime',
        'endtime',
    ];

    public function __construct(
        private readonly AboveFoldCacheService $aboveFoldCacheService,
    ) {}

    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        string|int $id,
        array $fieldArray,
        DataHandler $dataHandler
    ): void {
        // Only handle tt_content records
        if ($table !== 'tt_content') {
            return;
        }

        // Resolve real UID for new records
        $uid = $id;
        if (is_string($id) && str_starts_with($id, 'NEW')) {
            $uid = $dataHandler->substNEWwithIDs[$id] ?? 0;
        }
        $uid = (int)$uid;
        if ($uid <= 0) {
            return;
        }

        // Check if any position-relevant fields changed
        $changedFields = array_keys($fieldArray);
        $relevantChanges = array_intersect($changedFields, self::POSITION_RELEVANT_FIELDS);
        if ($relevantChanges === []) {
            return;
        }

        // Resolve pageUid via database lookup
        $pageUid = $this->resolvePageUid($uid);
        if ($pageUid <= 0) {
            return;
        }

        // Get all critical UIDs for the page
        $allCriticalUids = $this->aboveFoldCacheService->getAllCriticalUids($pageUid);
        if ($allCriticalUids === []) {
            return;
        }

        // Check if moved element UID is in any bucket's critical list
        $elementIsCritical = in_array($uid, $allCriticalUids, true);

        // Check if the new sorting of the moved element is <= any critical element's sorting in the same colPos
        $sortingConflict = false;
        if (isset($fieldArray['sorting']) || isset($fieldArray['colPos'])) {
            $sortingConflict = $this->checkSortingConflict($uid, $pageUid, $fieldArray, $allCriticalUids);
        }

        if ($elementIsCritical || $sortingConflict) {
            $this->aboveFoldCacheService->clearCriticalUids($pageUid);
            $this->aboveFoldCacheService->bumpResetTimestamp($pageUid);
            $this->flushPageCache($pageUid);
        }
    }

    private function resolvePageUid(int $elementUid): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');

        $result = $queryBuilder
            ->select('pid')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($elementUid, Connection::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $result !== false ? (int)$result['pid'] : 0;
    }

    private function checkSortingConflict(int $uid, int $pageUid, array $fieldArray, array $allCriticalUids): bool
    {
        $colPos = isset($fieldArray['colPos']) ? (int)$fieldArray['colPos'] : null;
        $newSorting = isset($fieldArray['sorting']) ? (int)$fieldArray['sorting'] : null;

        if ($colPos === null && $newSorting === null) {
            return false;
        }

        // Get critical elements' sorting in the same colPos
        if ($colPos === null || $newSorting === null) {
            return false;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');

        $rows = $queryBuilder
            ->select('uid', 'sorting')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter($colPos, Connection::PARAM_INT)),
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($allCriticalUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            if ($newSorting <= (int)$row['sorting']) {
                return true;
            }
        }

        return false;
    }

    private function flushPageCache(int $pageUid): void
    {
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cacheManager->flushCachesByTag('pageId_' . $pageUid);
    }
}
