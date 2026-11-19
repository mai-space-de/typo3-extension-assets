<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use Symfony\Component\Filesystem\Path;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Processing\MinificationProcessor;
use Maispace\MaiAssets\Processing\ScssProcessor;
use Maispace\MaiAssets\Utility\AssetPathUtility;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Single source of truth for "compile a CSS/SCSS source and return an absolute
 * file path the AssetCollector can register and the browser can fetch".
 *
 * Cache key is content-hash based (entry file + SCSS import tree), so edits to
 * the source or any partial invalidate the cached output naturally.
 */
final readonly class CompiledAssetPublisher
{
    private const string PUBLIC_CACHE_DIR = 'typo3temp/assets/mai_assets/compiled/';

    public function __construct(
        private ScssProcessor $scssProcessor,
        private MinificationProcessor $minificationProcessor,
        private ExtensionConfiguration $extensionConfiguration,
        private ScssDependencyHasher $scssDependencyHasher,
    ) {}

    /**
     * Compile (if SCSS), optionally minify, and return the absolute path to the
     * publishable CSS file. Plain CSS without minification short-circuits to the
     * source path — no needless cache copy.
     *
     * @param string $absoluteSourcePath Absolute path to a `.css` or `.scss` source.
     * @param bool|null $minify Override; null falls back to ExtensionConfiguration.
     */
    public function publishStylesheet(string $absoluteSourcePath, ?bool $minify = null): string
    {
        $ext = strtolower(pathinfo($absoluteSourcePath, PATHINFO_EXTENSION));
        $compileScss = $ext === 'scss' && $this->extensionConfiguration->isEnableScssProcessing();
        $shouldMinify = $minify ?? $this->extensionConfiguration->isEnableMinification();

        // Plain CSS the web server can deliver is linked directly. A file outside the public
        // directory (e.g. Resources/Private) is copied into the public cache directory.
        if (!$compileScss && !$shouldMinify && AssetPathUtility::isPubliclyReachable($absoluteSourcePath)) {
            return $absoluteSourcePath;
        }

        $cacheFile = $this->getCachePath($absoluteSourcePath, $compileScss, $shouldMinify);

        if (!file_exists($cacheFile)) {
            $content = (string) file_get_contents($absoluteSourcePath);
            if ($compileScss) {
                $content = $this->scssProcessor->process($content, $absoluteSourcePath);
            }
            if ($shouldMinify) {
                $content = $this->minificationProcessor->process($content, $absoluteSourcePath);
            }
            $content = $this->rewriteRelativeUrls($content, $absoluteSourcePath);
            GeneralUtility::mkdir_deep(dirname($cacheFile));
            file_put_contents($cacheFile, $content);
        }

        return $cacheFile;
    }

    /**
     * Compiled CSS is stored in typo3temp/, which breaks relative paths like ../Fonts/.
     * This method rewrites them to absolute web paths so fonts/images still load.
     */
    /**
     * Stylesheet content ready to be inlined into the HTML document: compiled and
     * minified like {@see publishStylesheet()}, with relative url() references made
     * absolute (they would otherwise resolve against the page URL).
     */
    public function getInlineStylesheet(string $absoluteSourcePath, ?bool $minify = null): string
    {
        $published = $this->publishStylesheet($absoluteSourcePath, $minify);
        $content = (string) file_get_contents($published);

        // Compiled files are rewritten already; a passed-through source file is not.
        return $published === $absoluteSourcePath
            ? $this->rewriteRelativeUrls($content, $absoluteSourcePath)
            : $content;
    }

    private function rewriteRelativeUrls(string $css, string $sourcePath): string
    {
        $sourceDir = dirname($sourcePath);

        $result = preg_replace_callback(
            '/url\(\s*[\'"]?([^\'"\)]+)[\'"]?\s*\)/i',
            function ($matches) use ($sourceDir) {
                $url = trim($matches[1]);

                if ($url === '' || preg_match('/^(https?:|data:|\/|#)/i', $url)) {
                    return $matches[0];
                }

                // Cache busters and fragments (font.woff2?v=2, font.svg#iefix) are kept but
                // must not take part in the file lookup.
                $suffixPosition = strcspn($url, '?#');
                $suffix = substr($url, $suffixPosition);
                $url = substr($url, 0, $suffixPosition);

                // Build absolute path without resolving symlinks
                $absolutePath = $sourceDir . '/' . $url;

                // Normalize the path using Symfony's Path normalizer if available, otherwise manual
                if (class_exists(Path::class)) {
                    $absolutePath = Path::canonicalize($absolutePath);
                } else {
                    // Manual normalization: remove /./ and resolve /../
                    $absolutePath = preg_replace('#/\./#', '/', $absolutePath);
                    while (preg_match('#/[^/]+/\.\./#', $absolutePath)) {
                        $absolutePath = preg_replace('#/[^/]+/\.\./#', '/', $absolutePath);
                    }
                }

                // Rewrite targets the browser can reach. A missing file in a public location is
                // rewritten as well: left relative it would resolve against the compiled file in
                // typo3temp (or the page URL when inlined) and certainly break.
                if (!file_exists($absolutePath) && !AssetPathUtility::isPubliclyReachable($absolutePath)) {
                    return $matches[0];
                }

                $webPath = $this->getPublicWebPath($absolutePath);
                return 'url("' . $webPath . $suffix . '")';
            },
            $css,
        );

        return $result;
    }

    /**
     * Get the public web path for a file, using TYPO3's asset URL resolution.
     */
    private function getPublicWebPath(string $absolutePath): string
    {
        // No realpath(): resolving symlinks would change the _assets hash.
        if (Environment::isComposerMode()
            && str_contains($absolutePath, 'Resources/Public')
            && str_starts_with($absolutePath, Environment::getProjectPath())
        ) {
            $relativePath = substr($absolutePath, strlen(Environment::getProjectPath()));
            [$relativePrefix, $relativeAssetPath] = explode('Resources/Public', $relativePath, 2);

            // A package from a local path repository (e.g. packages/my-ext/) is published by
            // Composer through its vendor/<name>/ symlink; the _assets hash uses that path.
            if (!str_contains($relativePrefix, '/vendor/')) {
                $relativePrefix = $this->vendorPrefixOfPackage(Environment::getProjectPath() . $relativePrefix) ?? $relativePrefix;
            }

            // Through PathUtility so the site path prefix (sub directory installs) is respected.
            return PathUtility::getAbsoluteWebPath(
                Environment::getPublicPath() . '/_assets/' . md5($relativePrefix) . $relativeAssetPath,
            );
        }

        return PathUtility::getAbsoluteWebPath($absolutePath);
    }

    /**
     * "/vendor/<composer name>/" when the package in $packageDirectory is installed into vendor/.
     */
    private function vendorPrefixOfPackage(string $packageDirectory): ?string
    {
        $composerFile = rtrim($packageDirectory, '/') . '/composer.json';
        if (!is_file($composerFile)) {
            return null;
        }
        $name = json_decode((string) file_get_contents($composerFile), true)['name'] ?? null;
        if (!is_string($name) || preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $name) !== 1
            || !is_dir(Environment::getProjectPath() . '/vendor/' . $name)
        ) {
            return null;
        }

        return '/vendor/' . $name . '/';
    }

    private function getCachePath(string $sourcePath, bool $compileScss, bool $minify): string
    {
        $sourceFingerprint = $compileScss
            ? $this->scssDependencyHasher->hash($sourcePath)
            : (string) hash_file('sha256', $sourcePath);

        // The source path is part of the key: identical content in different directories
        // gets different url() rewrites.
        $hash = md5(
            $sourcePath . ':' . $sourceFingerprint
            . ':scss=' . ($compileScss ? '1' : '0')
            . ':min=' . ($minify ? '1' : '0'),
        );
        return GeneralUtility::getFileAbsFileName(self::PUBLIC_CACHE_DIR) . $hash . '.css';
    }
}
