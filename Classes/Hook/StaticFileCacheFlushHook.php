<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Hook;

use Maispace\MaiAssets\StaticFileCache\StaticFileIndex;
use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * DataHandler clearCachePostProc hook: removes static HTML files whenever the
 * page cache of their page is cleared.
 *
 * DataHandler calls the hook in two shapes:
 * - record changes: ['table', 'uid', 'uid_page', 'tags' => [tag => true], ...]
 *   (called before the tags are flushed, so the index can still be searched);
 * - clear cache commands: ['cacheCmd' => 'all'|'pages'|'<pid>'|'cachetag:x', 'tags' => [tag, ...]].
 *
 * Serving additionally checks {@see StaticFileIndex::isValid()}, so this hook
 * mainly frees disk space and covers files a web server could deliver directly.
 */
#[Autoconfigure(public: true)]
final readonly class StaticFileCacheFlushHook
{
    public function __construct(
        private StaticFileRemovalService $removalService,
        private StaticFileIndex $index,
    ) {}

    /**
     * @param array<string, mixed> $params
     */
    public function clearCachePostProc(array $params): void
    {
        try {
            $this->process($params);
        } catch (\Throwable) {
            // Cache housekeeping must never break saving records.
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function process(array $params): void
    {
        $cacheCmd = isset($params['cacheCmd']) ? strtolower((string) $params['cacheCmd']) : null;
        if ($cacheCmd === 'all' || $cacheCmd === 'pages') {
            $this->removalService->purgeAll();

            return;
        }

        $tags = $this->normalizeTags($params['tags'] ?? [], $cacheCmd !== null);

        $pages = [];
        foreach ($tags as $tag) {
            if (preg_match('/^pageId_(\d+)$/', $tag, $matches) === 1) {
                $pages[(int) $matches[1]] = true;
            }
        }
        foreach ($this->index->findByTags($tags) as $entry) {
            $pages[$entry['page']] = true;
        }

        foreach (array_keys($pages) as $pageUid) {
            $this->removalService->purgeByPageUid($pageUid);
        }
    }

    /**
     * @return list<string>
     */
    private function normalizeTags(mixed $tags, bool $isList): array
    {
        if (!is_array($tags)) {
            return [];
        }

        $names = $isList ? array_values($tags) : array_keys($tags);

        return array_values(array_filter(array_map(strval(...), $names), static fn(string $tag): bool => $tag !== ''));
    }
}
