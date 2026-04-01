<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Event\ModifyCriticalThresholdEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class CriticalDetectionService
{
    public function __construct(
        private readonly AboveFoldCacheService $aboveFoldCacheService,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function isCritical(int $pageUid, int $elementUid): bool
    {
        // 1. Check tx_maiassets_force_critical DB field
        $record = $this->getContentRecord($elementUid);
        if ($record === null) {
            return false;
        }

        if ((bool)($record['tx_maiassets_force_critical'] ?? false)) {
            return true;
        }

        // 2. Check tx_maiassets_is_critical DB field — honour editor override
        if (array_key_exists('tx_maiassets_is_critical', $record)) {
            // If the editor explicitly set it to 0, honour that
            if ((int)$record['tx_maiassets_is_critical'] === 0) {
                return false;
            }
            if ((int)$record['tx_maiassets_is_critical'] === 1) {
                return true;
            }
        }

        // 3. Cache service result (from observer reports)
        $allCriticalUids = $this->aboveFoldCacheService->getAllCriticalUids($pageUid);
        if (in_array($elementUid, $allCriticalUids, true)) {
            return true;
        }

        // 4. Fallback: position/sorting heuristic
        $colPos = (int)($record['colPos'] ?? 0);
        $sorting = (int)($record['sorting'] ?? 0);
        $threshold = $this->getThresholdForColPos($colPos);

        if ($threshold <= 0) {
            return false;
        }

        // Count elements in same colPos with lower sorting (= higher on page)
        $position = $this->getElementPositionInColPos($pageUid, $colPos, $sorting);
        return $position < $threshold;
    }

    public function getThresholdForColPos(int $colPos): int
    {
        $thresholds = $this->extensionConfiguration->getCriticalThresholdByColPos();
        $threshold = (int)($thresholds[$colPos] ?? 0);

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
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');

        $result = $queryBuilder
            ->select('uid', 'pid', 'colPos', 'sorting', 'tx_maiassets_is_critical', 'tx_maiassets_force_critical')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($elementUid, \PDO::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();

        $row = $result->fetchAssociative();
        return $row !== false ? $row : null;
    }

    private function getElementPositionInColPos(int $pageUid, int $colPos, int $sorting): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');

        return (int)$queryBuilder
            ->count('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter($colPos, \PDO::PARAM_INT)),
                $queryBuilder->expr()->lt('sorting', $queryBuilder->createNamedParameter($sorting, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
            )
            ->executeQuery()
            ->fetchOne();
    }
}
