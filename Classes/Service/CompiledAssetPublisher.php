<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Processing\MinificationProcessor;
use Maispace\MaiAssets\Processing\ScssProcessor;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Single source of truth for "compile a CSS/SCSS source and return an absolute
 * file path the AssetCollector can register and the browser can fetch".
 *
 * Cache key is content-hash based, so edits to the source file invalidate the
 * cached output naturally — no manual cache flush required.
 */
final class CompiledAssetPublisher
{
    private const PUBLIC_CACHE_DIR = 'typo3temp/assets/mai_assets/compiled/';

    public function __construct(
        private readonly ScssProcessor $scssProcessor,
        private readonly MinificationProcessor $minificationProcessor,
        private readonly ExtensionConfiguration $extensionConfiguration,
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
            GeneralUtility::mkdir_deep(dirname($cacheFile));
            file_put_contents($cacheFile, $content);
        }

        return $cacheFile;
    }

    private function getCachePath(string $sourcePath, bool $compileScss, bool $minify): string
    {
        $hash = md5(
            (string)hash_file('sha256', $sourcePath)
            . ':scss=' . ($compileScss ? '1' : '0')
            . ':min=' . ($minify ? '1' : '0')
        );
        return GeneralUtility::getFileAbsFileName(self::PUBLIC_CACHE_DIR) . $hash . '.css';
    }
}
