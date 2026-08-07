<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EarlyHints;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\SingletonInterface;

final readonly class EarlyHintCacheService implements SingletonInterface
{
    private const string CACHE_IDENTIFIER = 'mai_assets_early_hints';

    private FrontendInterface $cache;

    public function __construct(CacheManager $cacheManager)
    {
        $this->cache = $cacheManager->getCache(self::CACHE_IDENTIFIER);
    }

    public function store(int $pageUid, int $languageUid, EarlyHintCandidateCollector $collector): void
    {
        if ($collector->isEmpty()) {
            return;
        }

        $key = $this->buildKey($pageUid, $languageUid);
        $serialized = $this->serializeCandidates($collector->getAll());
        $tags = ['mai_assets', 'pageId_' . $pageUid];

        $this->cache->set($key, $serialized, $tags);
    }

    /**
     * @return array<int, EarlyHintCandidate>
     */
    public function load(int $pageUid, int $languageUid): array
    {
        $key = $this->buildKey($pageUid, $languageUid);
        $data = $this->cache->get($key);

        if (!is_array($data)) {
            return [];
        }

        return $this->deserializeCandidates($data);
    }

    private function buildKey(int $pageUid, int $languageUid): string
    {
        return 'earlyhints_' . $pageUid . '_' . $languageUid;
    }

    /**
     * @param array<string, EarlyHintCandidate> $candidates
     * @return array<int, array<string, string>>
     */
    private function serializeCandidates(array $candidates): array
    {
        $rows = [];
        foreach ($candidates as $candidate) {
            $rows[] = [
                'href'        => $candidate->href,
                'rel'         => $candidate->rel,
                'as'          => $candidate->as,
                'type'        => $candidate->type,
                'crossorigin' => $candidate->crossorigin,
            ];
        }
        return $rows;
    }

    /**
     * $rows comes from deserialized cache data, so its shape isn't guaranteed
     * at runtime despite this best-effort type — hence the is_array() guard below.
     *
     * @param array<int, mixed> $rows
     * @return array<int, EarlyHintCandidate>
     */
    private function deserializeCandidates(array $rows): array
    {
        $candidates = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['href'], $row['rel'])) {
                continue;
            }
            $candidates[] = new EarlyHintCandidate(
                href: (string)$row['href'],
                rel: (string)$row['rel'],
                as: (string)($row['as'] ?? ''),
                type: (string)($row['type'] ?? ''),
                crossorigin: (string)($row['crossorigin'] ?? ''),
            );
        }
        return $candidates;
    }
}
