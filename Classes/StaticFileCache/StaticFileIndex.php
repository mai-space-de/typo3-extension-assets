<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\StaticFileCache;

use TYPO3\CMS\Core\Cache\Backend\TaggableBackendInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Validity index of the static HTML files.
 *
 * Every written file gets an entry in the cache "mai_assets_static_index" that
 * carries the same tags and lifetime as the TYPO3 page cache entry it was
 * rendered from. The cache belongs to the "pages" group, so whatever
 * invalidates the page cache (DataHandler tag flushes such as pageId_12 or
 * tx_news_pid_5, "flush frontend caches", lifetime ends caused by
 * starttime/endtime) invalidates the index entry as well.
 *
 * A static file without a valid index entry is stale and must not be served.
 */
class StaticFileIndex
{
    public const string CACHE_IDENTIFIER = 'mai_assets_static_index';

    /** Lifetime used when the page cache lifetime is unknown (TYPO3 default config.cache_period). */
    public const int DEFAULT_LIFETIME = 86400;

    /** Longer lifetimes are treated as unknown (TYPO3 uses PHP_INT_MAX for "unrestricted"). */
    public const int MAX_LIFETIME = 31536000;

    private const string TAG_PATTERN = '/^[a-zA-Z0-9_%\-&]{1,250}$/';

    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {}

    /**
     * @param list<string> $tags page cache tags of the rendered page
     * @param int $lifetime seconds; unknown values (0 or less, above {@see self::MAX_LIFETIME})
     *                      fall back to {@see self::DEFAULT_LIFETIME}
     */
    public function register(int $pageUid, int $languageUid, array $tags, int $lifetime, string $variant = ''): void
    {
        $tags[] = 'pageId_' . $pageUid;
        $tags = array_values(array_unique(array_filter(
            $tags,
            static fn(mixed $tag): bool => is_string($tag) && preg_match(self::TAG_PATTERN, $tag) === 1,
        )));

        $this->cache()->set(
            self::identifier($pageUid, $languageUid, $variant),
            ['page' => $pageUid, 'language' => $languageUid, 'variant' => $variant],
            $tags,
            $lifetime > 0 && $lifetime <= self::MAX_LIFETIME ? $lifetime : self::DEFAULT_LIFETIME,
        );
    }

    public function isValid(int $pageUid, int $languageUid, string $variant = ''): bool
    {
        return $this->cache()->has(self::identifier($pageUid, $languageUid, $variant));
    }

    public function remove(int $pageUid, int $languageUid, string $variant = ''): void
    {
        $this->cache()->remove(self::identifier($pageUid, $languageUid, $variant));
    }

    /**
     * Static files rendered from page cache entries carrying one of the tags.
     *
     * @param list<string> $tags
     * @return list<array{page: int, language: int, variant: string}>
     */
    public function findByTags(array $tags): array
    {
        $backend = $this->cache()->getBackend();
        if (!$backend instanceof TaggableBackendInterface) {
            return [];
        }

        $found = [];
        foreach ($tags as $tag) {
            if (!is_string($tag) || preg_match(self::TAG_PATTERN, $tag) !== 1) {
                continue;
            }
            foreach ($backend->findIdentifiersByTag($tag) as $identifier) {
                $entry = self::parseIdentifier((string) $identifier);
                if ($entry !== null) {
                    $found[$identifier] = $entry;
                }
            }
        }

        return array_values($found);
    }

    public static function identifier(int $pageUid, int $languageUid, string $variant = ''): string
    {
        return 'static_' . $pageUid . '_' . $languageUid . ($variant !== '' ? '_' . $variant : '');
    }

    /**
     * @return array{page: int, language: int, variant: string}|null
     */
    public static function parseIdentifier(string $identifier): ?array
    {
        if (preg_match('/^static_(\d+)_(\d+)(?:_([a-f0-9]{20}))?$/', $identifier, $matches) !== 1) {
            return null;
        }

        return ['page' => (int) $matches[1], 'language' => (int) $matches[2], 'variant' => $matches[3] ?? ''];
    }

    private function cache(): FrontendInterface
    {
        return $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
    }
}
