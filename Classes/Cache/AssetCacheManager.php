<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Cache;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Manages the maispace_assets caching framework cache.
 *
 * All processed assets (minified CSS/JS, compiled SCSS, SVG sprites) are cached here
 * to avoid re-processing on every request. The cache is grouped with the TYPO3 page cache,
 * so flushing the page cache also invalidates all processed assets.
 *
 * Cache key strategies:
 * - CSS/JS:   sha1(identifier + minify flag) to avoid collisions between minified and raw variants
 * - SCSS:     sha1(identifier + filemtime) for file-based (auto-invalidates on file change)
 *             sha1(identifier + content hash) for inline
 * - SVG:      sha1(sorted symbol IDs)
 */
final class AssetCacheManager
{
    private FrontendInterface $cache;

    public function __construct(CacheManager $cacheManager)
    {
        $this->cache = $cacheManager->getCache('maispace_assets');
    }

    public function get(string $key): mixed
    {
        return $this->cache->get($key);
    }

    /**
     * @param array<string> $tags
     */
    public function set(string $key, mixed $data, array $tags = [], int $lifetime = 0): void
    {
        $this->cache->set($key, $data, $tags, $lifetime);
    }

    public function has(string $key): bool
    {
        return $this->cache->has($key);
    }

    public function flush(): void
    {
        $this->cache->flush();
    }

    public function flushByTag(string $tag): void
    {
        $this->cache->flushByTag($tag);
    }

    /**
     * Cache key for a processed CSS asset.
     * Includes the minify flag so minified and raw variants coexist without collision.
     */
    public function buildCssKey(string $identifier, bool $minify): string
    {
        return 'css_' . sha1($identifier . ($minify ? '_min' : '_raw'));
    }

    /**
     * Cache key for a processed JS asset.
     */
    public function buildJsKey(string $identifier, bool $minify): string
    {
        return 'js_' . sha1($identifier . ($minify ? '_min' : '_raw'));
    }

    /**
     * Cache key for a compiled SCSS asset.
     *
     * For file-based SCSS, pass the file modification timestamp so the cache is automatically
     * invalidated when the source file changes — no manual flush needed during development.
     *
     * For inline SCSS (from Fluid template content), pass null; the identifier is already
     * derived from the content hash, so any content change produces a new key.
     */
    public function buildScssKey(string $identifier, ?int $fileMtime = null): string
    {
        $suffix = $fileMtime !== null ? '_file_' . $fileMtime : '_inline';

        return 'scss_' . sha1($identifier . $suffix);
    }

    /**
     * Cache key for a built SVG sprite.
     * Derived from the sorted list of symbol IDs that were registered in this request.
     *
     * @param array<int|string> $symbolIds
     */
    public function buildSpriteKey(array $symbolIds): string
    {
        $sorted = $symbolIds;
        sort($sorted);

        return 'svg_sprite_' . sha1(implode('|', $sorted));
    }

    /**
     * Cache key for per-page critical CSS at a specific viewport.
     *
     * Key format: critical_css_{sha1(pageUid|viewport)}
     * This keeps keys deterministic and collision-free across pages and viewports.
     */
    public function buildCriticalCssKey(int $pageUid, string $viewport): string
    {
        return 'critical_css_' . sha1($pageUid . '|' . $viewport);
    }

    /**
     * Cache key for per-page critical JS at a specific viewport.
     *
     * Key format: critical_js_{sha1(pageUid|viewport)}
     */
    public function buildCriticalJsKey(int $pageUid, string $viewport): string
    {
        return 'critical_js_' . sha1($pageUid . '|' . $viewport);
    }
}
