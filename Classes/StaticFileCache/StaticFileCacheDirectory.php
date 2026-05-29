<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\StaticFileCache;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Manages the filesystem directory layout for the static file cache.
 *
 * Static HTML files are written under:
 *   {public}/<staticFileCacheDir>/{scheme}_{host}_{port}/{url-path}/
 *
 * The default root directory is typo3temp/assets/mai_assets_static/.
 * It can be overridden via Extension Configuration (staticFileCacheDir).
 *
 * This class provides path resolution and validation only — it never writes
 * files itself. The GeneratorService uses this class to determine where to
 * place a cached page response.
 */
class StaticFileCacheDirectory
{
    /**
     * Default cache root, relative to the TYPO3 public/ docroot.
     */
    private const DEFAULT_RELATIVE_DIR = 'typo3temp/assets/mai_assets_static/';

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Get the absolute base directory for all static file cache entries.
     * Always ends with a directory separator.
     */
    public function getAbsoluteBaseDirectory(): string
    {
        $configured = $this->extensionConfiguration->getStaticFileCacheDir();
        $relativeDir = $configured !== ''
            ? rtrim($configured, '/') . '/'
            : self::DEFAULT_RELATIVE_DIR;

        return GeneralUtility::resolveBackPath(
            Environment::getPublicPath() . '/' . $relativeDir
        );
    }

    /**
     * Get the absolute directory path that will hold the cached HTML and
     * companion files (gzip variant, .config.json, …) for a specific page URI.
     *
     * Path scheme: {base}/{scheme}_{host}_{port}/{url-path}/
     *
     * The directory is not created; call ensureDirectoryExists() when needed.
     *
     * @throws \InvalidArgumentException When $requestUri is not a valid absolute URL.
     */
    public function getPageDirectory(string $requestUri): string
    {
        if (!$this->isValidUri($requestUri)) {
            throw new \InvalidArgumentException(
                'Cannot derive a static cache directory from "' . $requestUri
                . '": not a valid absolute URL with scheme, host, and path.',
                1748476800
            );
        }

        $baseDir = $this->getAbsoluteBaseDirectory();
        $relative = $this->buildRelativePagePath($requestUri);
        $resultPath = GeneralUtility::resolveBackPath($baseDir . $relative);

        // Security guard: the resolved path must stay within the cache base directory.
        if (!str_starts_with($resultPath, $baseDir)) {
            throw new \InvalidArgumentException(
                'Resolved path "' . $resultPath . '" is outside the cache base directory "' . $baseDir . '".',
                1748476801
            );
        }

        return $resultPath;
    }

    /**
     * Build the relative page path (no leading slash, trailing slash included):
     *   {scheme}_{host}_{port}/{url-path}/
     *
     * This method isolates the pure URL-to-path mapping so it can be tested
     * without a real TYPO3 environment.
     *
     * @throws \InvalidArgumentException When $requestUri is not valid.
     */
    public function buildRelativePagePath(string $requestUri): string
    {
        if (!$this->isValidUri($requestUri)) {
            throw new \InvalidArgumentException(
                'Cannot build relative page path from "' . $requestUri . '": not a valid absolute URL.',
                1748476802
            );
        }

        $hostSegment = $this->buildHostSegment($requestUri);
        $pathSegment = $this->buildPathSegment($requestUri);

        // When the URL path is the root ("/"), pathSegment is empty after trimming.
        // Avoid a double trailing slash by concatenating conditionally.
        return $pathSegment !== ''
            ? $hostSegment . '/' . $pathSegment . '/'
            : $hostSegment . '/';
    }

    /**
     * Return whether a URI is usable as a static cache key.
     * A valid URI must have a scheme, host, and path component.
     */
    public function isValidUri(string $uri): bool
    {
        if (!GeneralUtility::isValidUrl($uri)) {
            return false;
        }
        $parts = parse_url($uri);
        return isset($parts['scheme'], $parts['host'], $parts['path'])
            && $parts['scheme'] !== ''
            && $parts['host'] !== ''
            && $parts['path'] !== '';
    }

    /**
     * Create all missing parent directories for the given absolute path.
     */
    public function ensureDirectoryExists(string $absolutePath): void
    {
        GeneralUtility::mkdir_deep($absolutePath);
    }

    /**
     * Build the host-based segment: "{scheme}_{host}_{port}".
     * Port defaults to 443 for https and 80 for http when not explicitly set.
     */
    private function buildHostSegment(string $requestUri): string
    {
        $parts = parse_url($requestUri);
        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = isset($parts['port'])
            ? (int)$parts['port']
            : ($scheme === 'https' ? 443 : 80);

        return $scheme . '_' . $host . '_' . $port;
    }

    /**
     * Build the URL-path segment with leading and trailing slashes stripped.
     * Query strings and fragments are intentionally ignored.
     */
    private function buildPathSegment(string $requestUri): string
    {
        $parts = parse_url($requestUri);
        return trim((string)($parts['path'] ?? ''), '/');
    }

    /**
     * Get the absolute directory path that will hold the cached HTML for a
     * specific page UID and language. This is the page-ID-based variant of
     * getPageDirectory() — it derives the path from the numeric page and
     * language identifiers rather than from the request URI.
     *
     * Path scheme: {base}/{pageUid}_{languageUid}/
     *
     * This scheme mirrors the early-hints manifest cache key
     * (earlyhints_{pageUid}_{languageUid}) so both caches are naturally
     * aligned per page+language combination.
     *
     * The directory is not created; call ensureDirectoryExists() when needed.
     *
     * @throws \InvalidArgumentException When $pageUid <= 0 or $languageUid < 0.
     */
    public function getPageDirectoryById(int $pageUid, int $languageUid): string
    {
        if ($pageUid <= 0) {
            throw new \InvalidArgumentException(
                'Page UID must be positive, got ' . $pageUid . '.',
                1748534400
            );
        }
        if ($languageUid < 0) {
            throw new \InvalidArgumentException(
                'Language UID must be non-negative, got ' . $languageUid . '.',
                1748534401
            );
        }

        $baseDir = $this->getAbsoluteBaseDirectory();
        $relative = $this->buildRelativePagePathById($pageUid, $languageUid);
        $resultPath = GeneralUtility::resolveBackPath($baseDir . $relative);

        // Security guard: the resolved path must stay within the cache base directory.
        if (!str_starts_with($resultPath, $baseDir)) {
            throw new \InvalidArgumentException(
                'Resolved path "' . $resultPath . '" is outside the cache base directory "' . $baseDir . '".',
                1748534402
            );
        }

        return $resultPath;
    }

    /**
     * Build the relative page path from numeric identifiers (no leading slash,
     * trailing slash included):  {pageUid}_{languageUid}/
     *
     * This isolates the pure ID-to-path mapping so it can be tested without a
     * real TYPO3 environment.
     *
     * @throws \InvalidArgumentException When $pageUid <= 0 or $languageUid < 0.
     */
    public function buildRelativePagePathById(int $pageUid, int $languageUid): string
    {
        if ($pageUid <= 0) {
            throw new \InvalidArgumentException(
                'Page UID must be positive, got ' . $pageUid . '.',
                1748534403
            );
        }
        if ($languageUid < 0) {
            throw new \InvalidArgumentException(
                'Language UID must be non-negative, got ' . $languageUid . '.',
                1748534404
            );
        }

        return $pageUid . '_' . $languageUid . '/';
    }
}
