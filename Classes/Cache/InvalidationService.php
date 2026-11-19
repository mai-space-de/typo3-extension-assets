<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Cache;

use Maispace\MaiAssets\Event\AfterCacheInvalidationEvent;
use Maispace\MaiAssets\Event\BeforeCacheInvalidationEvent;
use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\SingletonInterface;

class InvalidationService implements SingletonInterface
{
    public const TARGET_PAGE_CACHE = 'page_cache';
    public const TARGET_EARLY_HINTS = 'early_hints';
    public const TARGET_STATIC_FILE = 'static_file';
    public const TARGET_ABOVE_FOLD = 'above_fold';
    /** Ask browsers to report again (new reset timestamp) but keep the collected data. */
    public const TARGET_ABOVE_FOLD_RECHECK = 'above_fold_recheck';

    /**
     * Fields that move elements or change their size substantially: their change
     * invalidates the collected above-fold data (and with it the page's readiness).
     */
    private const array POSITION_FIELDS = [
        'pid', 'colPos', 'sorting', 'hidden', 'deleted', 'starttime', 'endtime', 'fe_group',
        'sys_language_uid', 'CType', 'list_type', 'layout', 'frame_class', 'space_before_class',
        'space_after_class', 'imageorient', 'imagecols', 'imagewidth', 'imageheight', 'image',
        'assets', 'media', 'tx_container_parent', 'tx_maiassets_is_critical', 'tx_maiassets_force_critical',
    ];

    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AboveFoldCacheService $aboveFoldCacheService,
        private readonly StaticFileRemovalService $staticFileRemovalService,
    ) {}

    /**
     * Content save → page cache, early hints, static file.
     *
     * When position-relevant fields change (sorting, colPos, pid, hidden, layout, images, …) the
     * above-fold observer data is reset; other changes only request a new report from browsers.
     */
    public function invalidateAfterContentSave(int $pageUid, array $changedFields): void
    {
        $targets = $this->resolveContentSaveTargets($changedFields);

        if ($targets === []) {
            return;
        }

        $this->executeInvalidation(
            BeforeCacheInvalidationEvent::TRIGGER_CONTENT_SAVE,
            $pageUid,
            $targets,
            ['changedFields' => $changedFields],
        );
    }

    /**
     * Bucket update → page cache, early hints, static file.
     */
    public function invalidateAfterBucketUpdate(int $pageUid, string $bucket): void
    {
        $this->executeInvalidation(
            BeforeCacheInvalidationEvent::TRIGGER_BUCKET_UPDATE,
            $pageUid,
            [self::TARGET_PAGE_CACHE, self::TARGET_EARLY_HINTS, self::TARGET_STATIC_FILE],
            ['bucket' => $bucket],
        );
    }

    /**
     * @param array<string> $changedFields
     * @return array<string>
     */
    private function resolveContentSaveTargets(array $changedFields): array
    {
        // Position or layout changes reset the above-fold data. Other changes (texts) only
        // ask browsers to report again: newly visible elements are merged in, while the
        // page keeps its readiness and therefore its static file cache.
        $aboveFoldTarget = array_intersect($changedFields, self::POSITION_FIELDS) !== []
            ? self::TARGET_ABOVE_FOLD
            : self::TARGET_ABOVE_FOLD_RECHECK;

        return [
            $aboveFoldTarget,
            self::TARGET_PAGE_CACHE,
            self::TARGET_EARLY_HINTS,
            self::TARGET_STATIC_FILE,
        ];
    }

    /**
     * @param array<string> $targets
     * @param array<string, mixed> $context
     */
    private function executeInvalidation(string $trigger, int $pageUid, array $targets, array $context): void
    {
        $beforeEvent = new BeforeCacheInvalidationEvent($trigger, $pageUid, $targets, $context);
        $this->eventDispatcher->dispatch($beforeEvent);

        $finalTargets = $beforeEvent->getTargets();
        if ($finalTargets === []) {
            return;
        }

        $invalidatedTargets = [];
        $pageTagFlushed = false;
        $staticFilesPurged = false;

        foreach ($finalTargets as $target) {
            $success = match ($target) {
                self::TARGET_PAGE_CACHE, self::TARGET_EARLY_HINTS => $this->flushByPageTag($pageUid, $pageTagFlushed),
                self::TARGET_STATIC_FILE => $this->purgeStaticFiles($pageUid, $staticFilesPurged),
                self::TARGET_ABOVE_FOLD     => $this->clearAboveFoldCache($pageUid),
                self::TARGET_ABOVE_FOLD_RECHECK => $this->requestAboveFoldRecheck($pageUid),
                default                     => false,
            };

            if ($success) {
                $invalidatedTargets[] = $target;
            }
        }

        if ($invalidatedTargets !== []) {
            $afterEvent = new AfterCacheInvalidationEvent($trigger, $pageUid, $invalidatedTargets);
            $this->eventDispatcher->dispatch($afterEvent);
        }
    }

    private function flushByPageTag(int $pageUid, bool &$alreadyFlushed): bool
    {
        if ($alreadyFlushed) {
            return true;
        }

        try {
            $this->cacheManager->flushCachesByTag('pageId_' . $pageUid);
            $alreadyFlushed = true;
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private function clearAboveFoldCache(int $pageUid): bool
    {
        try {
            $this->aboveFoldCacheService->clearCriticalUids($pageUid);
            $this->aboveFoldCacheService->bumpResetTimestamp($pageUid);
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private function requestAboveFoldRecheck(int $pageUid): bool
    {
        try {
            $this->aboveFoldCacheService->bumpResetTimestamp($pageUid);
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private function purgeStaticFiles(int $pageUid, bool &$alreadyPurged): bool
    {
        if ($alreadyPurged) {
            return true;
        }

        try {
            $this->staticFileRemovalService->purgeByPageUid($pageUid);
            $alreadyPurged = true;
            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
