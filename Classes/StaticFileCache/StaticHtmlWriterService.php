<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\StaticFileCache;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;

/**
 * Writes a full-page HTML response to the static file cache on disk.
 *
 * The service enforces all guard conditions before writing:
 *
 * - Empty content is skipped.
 * - Pages containing TYPO3 INT_SCRIPT markers are non-cacheable and skipped.
 * - URIs carrying a query string are skipped to avoid ambiguous cache
 *   collisions (two different query-string variants of the same path would
 *   overwrite each other on the filesystem).
 * - Invalid or relative URIs are skipped.
 *
 * When compression is enabled via extension configuration, gzip (and
 * optionally Brotli) compressed variants are written alongside index.html
 * so the web server can serve pre-compressed content directly.
 *
 * Note: the filesystem-dependent code paths (getPageDirectory,
 * ensureDirectoryExists, file_put_contents) require a running TYPO3
 * environment. Those paths are covered by functional tests, not unit tests.
 */
class StaticHtmlWriterService
{
    /**
     * Filename written for each cached page directory.
     */
    public const INDEX_FILENAME = 'index.html';

    /**
     * TYPO3 non-cacheable page marker. Pages containing this string are
     * rendered with USER_INT / COA_INT objects and must not be statically cached.
     */
    private const INT_MARKER = '<!--INT_SCRIPT.';

    public function __construct(
        private readonly StaticFileCacheDirectory $cacheDirectory,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Write the given HTML to the static file cache for $requestUri.
     *
     * Returns true when the file was successfully written, false when any
     * guard condition prevented the write.
     */
    public function write(string $html, string $requestUri): bool
    {
        if ($html === '') {
            return false;
        }

        // Pages with USER_INT / COA_INT objects include this marker in the
        // rendered output — they are non-cacheable and must not be written.
        if (str_contains($html, self::INT_MARKER)) {
            return false;
        }

        // Skip URIs with a query string: two requests to /page/?a=1 and
        // /page/?a=2 would map to the same index.html file and overwrite
        // each other. Only parameter-free, canonical URLs are cached.
        if ($this->hasQueryString($requestUri)) {
            return false;
        }

        if (!$this->cacheDirectory->isValidUri($requestUri)) {
            return false;
        }

        $pageDir = $this->cacheDirectory->getPageDirectory($requestUri);
        $this->cacheDirectory->ensureDirectoryExists($pageDir);

        $filePath = rtrim($pageDir, '/') . '/' . self::INDEX_FILENAME;

        if (file_put_contents($filePath, $html) === false) {
            return false;
        }

        if ($this->extensionConfiguration->isEnableCompression()) {
            $this->writeCompressedVariants($filePath, $html);
        }

        return true;
    }

    /**
     * Write the given HTML to the static file cache keyed by page UID and
     * language UID rather than by request URI.
     *
     * The cache directory is derived from the numeric identifiers:
     *   {baseDir}/{pageUid}_{languageUid}/index.html
     *
     * This scheme mirrors the early-hints manifest cache key
     * (earlyhints_{pageUid}_{languageUid}) so both caches are naturally
     * aligned per page+language combination.
     *
     * The same content guards as write() apply: empty content and
     * INT_SCRIPT markers are skipped. URI-based guards (query strings,
     * validity) are not applicable and are omitted.
     *
     * Returns true when the file was successfully written, false when any
     * guard condition prevented it or the write itself failed.
     */
    public function writeByPageId(string $html, int $pageUid, int $languageUid): bool
    {
        if ($html === '') {
            return false;
        }

        if (str_contains($html, self::INT_MARKER)) {
            return false;
        }

        if ($pageUid <= 0) {
            return false;
        }

        if ($languageUid < 0) {
            return false;
        }

        $pageDir = $this->cacheDirectory->getPageDirectoryById($pageUid, $languageUid);
        $this->cacheDirectory->ensureDirectoryExists($pageDir);

        $filePath = rtrim($pageDir, '/') . '/' . self::INDEX_FILENAME;

        if (file_put_contents($filePath, $html) === false) {
            return false;
        }

        if ($this->extensionConfiguration->isEnableCompression()) {
            $this->writeCompressedVariants($filePath, $html);
        }

        return true;
    }

    /**
     * Return true when the URI carries a non-empty query string.
     */
    private function hasQueryString(string $uri): bool
    {
        $parts = parse_url($uri);

        return isset($parts['query']) && $parts['query'] !== '';
    }

    /**
     * Write gzip (and optionally Brotli) compressed variants alongside the
     * plain HTML file. Failures are silently ignored — the uncompressed file
     * is always the authoritative copy.
     */
    private function writeCompressedVariants(string $filePath, string $html): void
    {
        $level = $this->extensionConfiguration->getCompressionLevel();

        $gzContent = gzencode($html, $level);
        if ($gzContent !== false) {
            file_put_contents($filePath . '.gz', $gzContent);
        }

        if ($this->extensionConfiguration->isEnableBrotli() && function_exists('brotli_compress')) {
            /** @var string|false $brContent */
            $brContent = brotli_compress($html, $level);
            if ($brContent !== false) {
                file_put_contents($filePath . '.br', $brContent);
            }
        }
    }
}
