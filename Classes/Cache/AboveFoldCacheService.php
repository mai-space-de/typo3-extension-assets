<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Cache;

use Maispace\MaiAssets\Event\AfterCriticalUidsUpdatedEvent;
use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use Maispace\MaiAssets\Traits\CacheKeyTrait;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\SingletonInterface;

final class AboveFoldCacheService implements SingletonInterface
{
    use CacheKeyTrait;

    private const string CACHE_IDENTIFIER = 'mai_assets_above_fold';

    private FrontendInterface $cache;

    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly StaticFileRemovalService $staticFileRemovalService,
    ) {
        $this->cache = $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
    }

    public function getCriticalUids(int $pageUid, string $bucket): array
    {
        $key = $this->buildCacheKey($pageUid, $bucket);
        $data = $this->cache->get($key);
        if (!is_array($data)) {
            return [];
        }
        return $data;
    }

    /**
     * @return list<string> Stored bucket names for this page, or empty array if none.
     */
    public function getBucketNames(int $pageUid): array
    {
        $indexKey = 'buckets_' . $pageUid;
        $buckets = $this->cache->get($indexKey);
        if (!is_array($buckets)) {
            return [];
        }
        return array_values(array_map(strval(...), $buckets));
    }

    public function getAllCriticalUids(int $pageUid): array
    {
        // We cannot enumerate cache keys, so we rely on stored bucket index
        $buckets = $this->getBucketNames($pageUid);
        if ($buckets === []) {
            return [];
        }

        $merged = [];
        foreach ($buckets as $bucket) {
            $uids = $this->getCriticalUids($pageUid, $bucket);
            $merged = array_unique(array_merge($merged, $uids));
        }
        return array_values($merged);
    }

    /**
     * Store the critical UIDs reported for a viewport bucket.
     *
     * The bucket is registered in the bucket index even when its UID list is
     * identical to the stored one (e.g. empty on both sides). Readiness is
     * derived from that index, so a viewport that legitimately reports no
     * above-fold elements must still count as "observed".
     *
     * @return bool True when the bucket was newly registered or its UIDs changed,
     *              i.e. when page cache and derived artefacts must be refreshed.
     */
    public function updateCriticalUids(int $pageUid, string $bucket, array $newUids): bool
    {
        $existingUids = $this->getCriticalUids($pageUid, $bucket);
        $tags = ['mai_assets', 'maiAssetsAboveFold_' . $pageUid];

        $indexKey = 'buckets_' . $pageUid;
        $buckets = $this->cache->get($indexKey);
        if (!is_array($buckets)) {
            $buckets = [];
        }
        $bucketIsNew = !in_array($bucket, $buckets, true);

        $sortedNew = $newUids;
        $sortedExisting = $existingUids;
        sort($sortedNew);
        sort($sortedExisting);

        $uidsChanged = $sortedNew !== $sortedExisting;

        if (!$uidsChanged && !$bucketIsNew) {
            return false;
        }

        $this->cache->set($this->buildCacheKey($pageUid, $bucket), $sortedNew, $tags);

        if ($bucketIsNew) {
            $buckets[] = $bucket;
            $this->cache->set($indexKey, $buckets, $tags);
        }

        $this->eventDispatcher->dispatch(
            new AfterCriticalUidsUpdatedEvent($pageUid, $bucket, $existingUids, $sortedNew),
        );

        return true;
    }

    public function clearCriticalUids(int $pageUid): void
    {
        $indexKey = 'buckets_' . $pageUid;
        $buckets = $this->cache->get($indexKey);
        if (is_array($buckets)) {
            foreach ($buckets as $bucket) {
                $key = $this->buildCacheKey($pageUid, $bucket);
                $this->cache->remove($key);
            }
        }
        $this->cache->remove($indexKey);

        $this->staticFileRemovalService->purgeByPageUid($pageUid);
    }

    public function getResetTimestamp(int $pageUid): int
    {
        $key = $this->buildResetKey($pageUid);
        $timestamp = $this->cache->get($key);
        return is_int($timestamp) ? $timestamp : 0;
    }

    public function bumpResetTimestamp(int $pageUid): void
    {
        $key = $this->buildResetKey($pageUid);
        $this->cache->set($key, time(), ['mai_assets', 'maiAssetsAboveFold_' . $pageUid]);

        $this->staticFileRemovalService->purgeByPageUid($pageUid);
    }
}
