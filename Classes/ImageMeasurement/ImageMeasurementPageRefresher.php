<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ImageMeasurement;

use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * Re-renders pages whose srcset/sizes depend on measurements that changed or were deleted.
 *
 * Which pages render a slot is known from the reports ({@see ImageMeasurementRepository::rememberPage()}),
 * so shared layout slots and referenced content elements on other pages are covered as well.
 * Lookups fail soft: a missing table (database not yet compared) must never break measuring or saving.
 */
class ImageMeasurementPageRefresher
{
    public function __construct(
        private readonly ImageMeasurementRepository $repository,
        private readonly CacheManager $cacheManager,
        private readonly StaticFileRemovalService $staticFileRemovalService,
    ) {}

    /**
     * @return list<int>
     */
    public function pagesOfSlot(string $slotKey): array
    {
        try {
            return $this->repository->getPageUids($slotKey);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<int>
     */
    public function pagesOfElement(int $elementUid): array
    {
        try {
            return $this->repository->getPageUidsForElement($elementUid);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<int>
     */
    public function allPages(): array
    {
        try {
            return $this->repository->getAllPageUids();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Flushes page cache and static HTML of the given pages.
     *
     * @param iterable<int> $pageUids
     * @return int number of refreshed pages
     */
    public function refresh(iterable $pageUids): int
    {
        $refreshed = [];
        foreach ($pageUids as $pageUid) {
            $pageUid = (int) $pageUid;
            if ($pageUid <= 0 || isset($refreshed[$pageUid])) {
                continue;
            }
            $refreshed[$pageUid] = true;

            $this->cacheManager->flushCachesByTag('pageId_' . $pageUid);
            try {
                $this->staticFileRemovalService->purgeByPageUid($pageUid);
            } catch (\Throwable) {
                // Static file cache is optional.
            }
        }

        return count($refreshed);
    }
}
