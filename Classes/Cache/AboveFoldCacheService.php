<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Cache;

use Maispace\MaiAssets\Event\AfterCriticalUidsUpdatedEvent;
use Maispace\MaiAssets\Traits\CacheKeyTrait;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\SingletonInterface;

final class AboveFoldCacheService implements SingletonInterface
{
    use CacheKeyTrait;

    private const CACHE_IDENTIFIER = 'mai_assets_above_fold';

    private FrontendInterface $cache;

    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly EventDispatcherInterface $eventDispatcher,
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

    public function getAllCriticalUids(int $pageUid): array
    {
        // We cannot enumerate cache keys, so we rely on stored bucket index
        $indexKey = 'buckets_' . $pageUid;
        $buckets = $this->cache->get($indexKey);
        if (!is_array($buckets)) {
            return [];
        }

        $merged = [];
        foreach ($buckets as $bucket) {
            $uids = $this->getCriticalUids($pageUid, $bucket);
            $merged = array_unique(array_merge($merged, $uids));
        }
        return array_values($merged);
    }

    public function updateCriticalUids(int $pageUid, string $bucket, array $newUids): bool
    {
        $existingUids = $this->getCriticalUids($pageUid, $bucket);

        $sortedNew = $newUids;
        $sortedExisting = $existingUids;
        sort($sortedNew);
        sort($sortedExisting);

        if ($sortedNew === $sortedExisting) {
            return false;
        }

        $key = $this->buildCacheKey($pageUid, $bucket);
        $this->cache->set($key, $sortedNew, ['mai_assets', 'pageId_' . $pageUid]);

        // Update bucket index
        $indexKey = 'buckets_' . $pageUid;
        $buckets = $this->cache->get($indexKey);
        if (!is_array($buckets)) {
            $buckets = [];
        }
        if (!in_array($bucket, $buckets, true)) {
            $buckets[] = $bucket;
            $this->cache->set($indexKey, $buckets, ['mai_assets', 'pageId_' . $pageUid]);
        }

        $event = new AfterCriticalUidsUpdatedEvent($pageUid, $bucket, $existingUids, $sortedNew);
        $this->eventDispatcher->dispatch($event);

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
        $this->cache->set($key, time(), ['mai_assets', 'pageId_' . $pageUid]);
    }
}
