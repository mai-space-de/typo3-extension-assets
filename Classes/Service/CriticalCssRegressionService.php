<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use Maispace\MaiAssets\Exception\AssetException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Measures critical CSS byte sizes and detects regressions against baselines.
 * Used in CI to fail when critical CSS exceeds Core Web Vitals thresholds.
 */
final class CriticalCssRegressionService
{
    private const string BASELINE_FILE = 'EXT:mai_assets/Resources/Private/Baselines/critical-css-baseline.json';

    private ?string $overrideBaselinePath = null;

    public function __construct(
        private readonly CompiledAssetPublisher $compiledAssetPublisher,
    ) {}

    /**
     * Measure the compiled size of a critical CSS file.
     *
     * @param string $absoluteSourcePath Absolute path to a .css or .scss file
     * @param bool|null $minify Whether to minify (null uses extension config)
     * @return int Byte size of the compiled and minified CSS
     * @throws AssetException When file cannot be processed
     */
    public function measureCriticalCssSize(string $absoluteSourcePath, ?bool $minify = true): int
    {
        try {
            $compiledPath = $this->compiledAssetPublisher->publishStylesheet($absoluteSourcePath, $minify);
            return $this->measureCompiledFileSize($compiledPath);
        } catch (\Exception $e) {
            throw new AssetException(
                sprintf('Failed to measure CSS size for %s: %s', $absoluteSourcePath, $e->getMessage()),
                1715956800,
                $e
            );
        }
    }

    /**
     * Measure the byte size of a compiled CSS file. For testing purposes.
     *
     * @param string $compiledCssPath Absolute path to an already-compiled CSS file
     * @return int Byte size of the file
     * @throws AssetException When file cannot be read
     */
    public function measureCompiledFileSize(string $compiledCssPath): int
    {
        if (!file_exists($compiledCssPath) || !is_readable($compiledCssPath)) {
            throw new AssetException(
                sprintf('File not found or not readable: %s', $compiledCssPath),
                1715956802
            );
        }

        try {
            $content = (string)file_get_contents($compiledCssPath);
            return strlen($content);
        } catch (\Exception $e) {
            throw new AssetException(
                sprintf('Failed to measure file size for %s: %s', $compiledCssPath, $e->getMessage()),
                1715956802,
                $e
            );
        }
    }

    /**
     * Get the baseline configuration for critical CSS checks.
     *
     * @return array<string, int> Map of identifier to max byte size
     */
    public function getBaseline(): array
    {
        $baselinePath = $this->getBaselinePath();

        if (!file_exists($baselinePath)) {
            return [];
        }

        try {
            $json = (string)file_get_contents($baselinePath);
            $baseline = json_decode($json, true);

            if (!is_array($baseline)) {
                return [];
            }

            return array_filter($baseline, is_int(...));
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Check a critical CSS file against its baseline.
     *
     * @param string $identifier Unique identifier for the CSS file (e.g., 'mai-theme-main')
     * @param string $absoluteSourcePath Absolute path to the CSS/SCSS file
     * @param bool|null $minify Whether to minify
     * @return array{passed: bool, actual: int, baseline: ?int, exceeded_by: ?int}
     * @throws AssetException When file cannot be processed
     */
    public function checkRegressionForFile(
        string $identifier,
        string $absoluteSourcePath,
        ?bool $minify = true
    ): array {
        $actual = $this->measureCriticalCssSize($absoluteSourcePath, $minify);
        return $this->checkRegressionWithSize($identifier, $actual);
    }

    /**
     * Check a measured CSS size against its baseline. For testing and internal use.
     *
     * @param string $identifier Unique identifier for the CSS file
     * @param int $actualSize The measured byte size
     * @return array{passed: bool, actual: int, baseline: ?int, exceeded_by: ?int}
     */
    public function checkRegressionWithSize(string $identifier, int $actualSize): array
    {
        $baseline = $this->getBaseline()[$identifier] ?? null;

        if ($baseline === null) {
            return ['passed' => true, 'actual' => $actualSize, 'baseline' => null, 'exceeded_by' => null];
        }

        $exceeded = $actualSize > $baseline;
        $exceededBy = $exceeded ? $actualSize - $baseline : null;

        return [
            'passed' => !$exceeded,
            'actual' => $actualSize,
            'baseline' => $baseline,
            'exceeded_by' => $exceededBy,
        ];
    }

    /**
     * Update a baseline entry.
     *
     * @param string $identifier Unique identifier for the CSS file
     * @param int $byteSize The new baseline byte size
     * @throws AssetException When baseline cannot be written
     */
    public function setBaselineEntry(string $identifier, int $byteSize): void
    {
        $baselinePath = $this->getBaselinePath();
        $baseline = $this->getBaseline();
        $baseline[$identifier] = $byteSize;

        try {
            GeneralUtility::mkdir_deep(dirname($baselinePath));
            file_put_contents($baselinePath, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Exception $e) {
            throw new AssetException(
                sprintf('Failed to write baseline to %s: %s', $baselinePath, $e->getMessage()),
                1715956801,
                $e
            );
        }
    }

    /**
     * Override baseline path for testing. Internal use only.
     */
    public function setBaselinePathForTesting(string $path): void
    {
        $this->overrideBaselinePath = $path;
    }

    private function getBaselinePath(): string
    {
        if ($this->overrideBaselinePath !== null) {
            return $this->overrideBaselinePath;
        }

        return GeneralUtility::getFileAbsFileName(self::BASELINE_FILE);
    }
}
