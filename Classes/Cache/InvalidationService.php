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

    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AboveFoldCacheService $aboveFoldCacheService,
        private readonly StaticFileRemovalService $staticFileRemovalService,
    ) {}

    /**
     * Content save → page cache, early hints, static file (± above-fold for position changes).
     *
     * Position-relevant fields (sorting, colPos, pid, hidden, deleted, starttime, endtime)
     * additionally invalidate the above-fold observer cache.
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
            ['changedFields' => $changedFields]
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
            ['bucket' => $bucket]
        );
    }

    /**
     * @param array<string> $changedFields
     * @return array<string>
     */
    private function resolveContentSaveTargets(array $changedFields): array
    {
        $positionFields = ['sorting', 'colPos', 'pid', 'hidden', 'deleted', 'starttime', 'endtime'];
        $hasPositionChange = array_intersect($changedFields, $positionFields) !== [];

        if ($hasPositionChange) {
            return [
                self::TARGET_ABOVE_FOLD,
                self::TARGET_PAGE_CACHE,
                self::TARGET_EARLY_HINTS,
                self::TARGET_STATIC_FILE,
            ];
        }

        return [self::TARGET_PAGE_CACHE, self::TARGET_EARLY_HINTS, self::TARGET_STATIC_FILE];
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
