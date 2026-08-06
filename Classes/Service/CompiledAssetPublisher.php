<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Processing\MinificationProcessor;
use Maispace\MaiAssets\Processing\ScssProcessor;
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
final class CompiledAssetPublisher
{
    private const PUBLIC_CACHE_DIR = 'typo3temp/assets/mai_assets/compiled/';

    public function __construct(
        private readonly ScssProcessor $scssProcessor,
        private readonly MinificationProcessor $minificationProcessor,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ScssDependencyHasher $scssDependencyHasher,
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

        if (!$compileScss && !$shouldMinify) {
            return $absoluteSourcePath;
        }

        $cacheFile = $this->getCachePath($absoluteSourcePath, $compileScss, $shouldMinify);

        if (!file_exists($cacheFile)) {
            $content = (string)file_get_contents($absoluteSourcePath);
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
    private function rewriteRelativeUrls(string $css, string $sourcePath): string
    {
        $sourceDir = dirname($sourcePath);
        
        $result = preg_replace_callback(
            '/url\(\s*[\'"]?([^\'"\)]+)[\'"]?\s*\)/i',
            function ($matches) use ($sourceDir) {
                $url = $matches[1];
                
                if (preg_match('/^(https?:|data:|\/)/', $url)) {
                    return $matches[0];
                }
                
                // Build absolute path without resolving symlinks
                $absolutePath = $sourceDir . '/' . $url;
                
                // Normalize the path using Symfony's Path normalizer if available, otherwise manual
                if (class_exists('\Symfony\Component\Filesystem\Path')) {
                    $absolutePath = \Symfony\Component\Filesystem\Path::canonicalize($absolutePath);
                } else {
                    // Manual normalization: remove /./ and resolve /../
                    $absolutePath = preg_replace('#/\./#', '/', $absolutePath);
                    while (preg_match('#/[^/]+/\.\./#', $absolutePath)) {
                        $absolutePath = preg_replace('#/[^/]+/\.\./#', '/', $absolutePath);
                    }
                }
                
                // Check if file exists
                if (!file_exists($absolutePath)) {
                    return $matches[0];
                }
                
                $webPath = $this->getPublicWebPath($absolutePath);
                return 'url("' . $webPath . '")';
            },
            $css
        );
        
        return $result;
    }

    /**
     * Get the public web path for a file, using TYPO3's asset URL resolution.
     */
    private function getPublicWebPath(string $absolutePath): string
    {
        // Don't use realpath() here because it resolves symlinks, which breaks the hash calculation
        // Check if file is within project path and contains Resources/Public
        if (Environment::isComposerMode() 
            && str_contains($absolutePath, 'Resources/Public') 
            && str_starts_with($absolutePath, Environment::getProjectPath())) {
            
            $relativePath = substr($absolutePath, strlen(Environment::getProjectPath()));
            
            // Check if the path is in the vendor directory (even if it's a symlink)
            if (str_contains($relativePath, '/vendor/')) {
                [$relativePrefix, $relativeAssetPath] = explode('Resources/Public', $relativePath, 2);
                return '/_assets/' . md5($relativePrefix) . $relativeAssetPath;
            }
            
            // If the path is in the packages directory, convert it to the vendor path
            if (str_contains($relativePath, '/packages/')) {
                // Extract the package name from the path
                if (preg_match('#/packages/typo3-extension-([^/]+)/#', $relativePath, $matches)) {
                    $packageName = $matches[1];
                    // Convert packages/typo3-extension-xxx to vendor/maispace/xxx
                    $vendorPath = '/vendor/maispace/' . $packageName . '/';
                    $assetPath = substr($relativePath, strpos($relativePath, 'Resources/Public') + strlen('Resources/Public'));
                    return '/_assets/' . md5($vendorPath) . $assetPath;
                }
            }
        }
        
        // Fallback to standard PathUtility for non-extension files
        return PathUtility::getAbsoluteWebPath($absolutePath);
    }

    private function getCachePath(string $sourcePath, bool $compileScss, bool $minify): string
    {
        $sourceFingerprint = $compileScss
            ? $this->scssDependencyHasher->hash($sourcePath)
            : (string)hash_file('sha256', $sourcePath);

        $hash = md5(
            $sourceFingerprint
            . ':scss=' . ($compileScss ? '1' : '0')
            . ':min=' . ($minify ? '1' : '0')
        );
        return GeneralUtility::getFileAbsFileName(self::PUBLIC_CACHE_DIR) . $hash . '.css';
    }
}
