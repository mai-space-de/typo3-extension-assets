<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Configuration;

use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Core\Environment;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ExtensionConfiguration
{
    private const string EXTENSION_KEY = 'mai_assets';

    private bool $enableScssProcessing;
    private bool $enableMinification;
    private bool $enableCompression;
    private int $compressionLevel;
    private bool $enableBrotli;
    private array $criticalThresholdByColPos;
    private array $viewportBuckets;
    private array $svgStripAttributes;
    private array $fontPreloadFormats;
    private string $observerRootMargin;
    private int $processingCacheLifetime;
    private bool $enableStaticFileCache;
    private string $staticFileCacheDir;
    private bool $debugHeaders;
    private string $useDevServer;
    private string $devServerUri;
    private bool $enableImageMeasurement;
    private int $imageMeasureSampleRate;
    private int $criticalCssMaxInlineBytes;

    public function __construct(Typo3ExtensionConfiguration $typo3ExtensionConfiguration)
    {
        $raw = [];
        try {
            $raw = $typo3ExtensionConfiguration->get(self::EXTENSION_KEY);
        } catch (Exception) {
            // Extension configuration not yet saved — use defaults
        }

        $this->enableScssProcessing = (bool) ($raw['enableScssProcessing'] ?? true);
        $this->enableMinification = (bool) ($raw['enableMinification'] ?? true);
        $this->enableCompression = (bool) ($raw['enableCompression'] ?? true);
        $this->compressionLevel = (int) ($raw['compressionLevel'] ?? 6);
        $this->enableBrotli = (bool) ($raw['enableBrotli'] ?? true);
        // Arrays (settings.php) or the string form of ext_conf_template.txt.
        $this->criticalThresholdByColPos = self::parseIntMap($raw['criticalThresholdByColPos'] ?? null, [0 => 2, 1 => 0, 3 => 0]);
        $this->viewportBuckets = self::parseIntMap($raw['viewportBuckets'] ?? null, ['mobile' => 768, 'tablet' => 1024, 'desktop' => PHP_INT_MAX]);
        $this->svgStripAttributes = (array) ($raw['svgStripAttributes'] ?? ['id', 'class', 'style']);
        $this->fontPreloadFormats = self::parseList($raw['fontPreloadFormats'] ?? null, ['woff2']);
        $this->observerRootMargin = self::sanitizeRootMargin((string) ($raw['observerRootMargin'] ?? '0px'));
        $this->processingCacheLifetime = (int) ($raw['processingCacheLifetime'] ?? 0);
        $this->enableStaticFileCache = (bool) ($raw['enableStaticFileCache'] ?? false);
        $this->staticFileCacheDir = (string) ($raw['staticFileCacheDir'] ?? '');
        $this->debugHeaders = (bool) ($raw['debugHeaders'] ?? false);
        $this->useDevServer = (string) ($raw['useDevServer'] ?? 'auto');
        $this->devServerUri = (string) ($raw['devServerUri'] ?? 'http://localhost:5173');
        $this->enableImageMeasurement = (bool) ($raw['enableImageMeasurement'] ?? true);
        $this->imageMeasureSampleRate = max(1, min(100, (int) ($raw['imageMeasureSampleRate'] ?? 100)));
        $this->criticalCssMaxInlineBytes = max(0, (int) ($raw['criticalCssMaxInlineBytes'] ?? 14336));
    }

    public static function getInstance(): self
    {
        return GeneralUtility::makeInstance(self::class);
    }

    public function isEnableScssProcessing(): bool
    {
        return $this->enableScssProcessing;
    }

    public function isEnableMinification(): bool
    {
        return $this->enableMinification;
    }

    public function isEnableCompression(): bool
    {
        return $this->enableCompression;
    }

    public function getCompressionLevel(): int
    {
        return $this->compressionLevel;
    }

    public function isEnableBrotli(): bool
    {
        return $this->enableBrotli;
    }

    public function getCriticalThresholdByColPos(): array
    {
        return $this->criticalThresholdByColPos;
    }

    public function getViewportBuckets(): array
    {
        return $this->viewportBuckets;
    }

    public function getSvgStripAttributes(): array
    {
        return $this->svgStripAttributes;
    }

    public function getFontPreloadFormats(): array
    {
        return $this->fontPreloadFormats;
    }

    public function getObserverRootMargin(): string
    {
        return $this->observerRootMargin;
    }

    public function getProcessingCacheLifetime(): int
    {
        return $this->processingCacheLifetime;
    }

    public function isEnableStaticFileCache(): bool
    {
        return $this->enableStaticFileCache;
    }

    /**
     * Override directory for static HTML files, relative to the public docroot.
     * Empty string means the default path should be used.
     */
    public function getStaticFileCacheDir(): string
    {
        return $this->staticFileCacheDir;
    }

    /**
     * Whether debug headers (x-mai-static-ready, x-mai-static-cache)
     * should be emitted on responses for ops and QA inspection.
     */
    public function isDebugHeaders(): bool
    {
        return $this->debugHeaders;
    }

    public function isUseDevServer(): bool
    {
        if ($this->useDevServer === 'auto') {
            return Environment::getContext()->isDevelopment();
        }
        return (bool) $this->useDevServer;
    }

    public function getDevServerUri(): UriInterface
    {
        return new Uri($this->devServerUri);
    }

    /**
     * Whether image slots are measured in the browser to derive sizes/srcset.
     */
    public function isEnableImageMeasurement(): bool
    {
        return $this->enableImageMeasurement;
    }

    /**
     * Percentage (1-100) of page views that report image measurements.
     */
    /**
     * Largest stylesheet (bytes) that critical="auto" inlines; 0 = no limit.
     */
    public function getCriticalCssMaxInlineBytes(): int
    {
        return $this->criticalCssMaxInlineBytes;
    }

    public function getImageMeasureSampleRate(): int
    {
        return $this->imageMeasureSampleRate;
    }

    /**
     * The value ends up inside a JavaScript string: only 1-4 lengths in px or % are accepted.
     */
    private static function sanitizeRootMargin(string $value): string
    {
        $value = trim($value);

        return preg_match('/^-?\d+(\.\d+)?(px|%)(\s+-?\d+(\.\d+)?(px|%)){0,3}$/', $value) === 1 ? $value : '0px';
    }

    /**
     * "mobile:768, tablet:1024, desktop" (a key without value is unlimited) or an array.
     *
     * @param array<int|string, int> $default
     * @return array<int|string, int>
     */
    private static function parseIntMap(mixed $value, array $default): array
    {
        if (is_array($value)) {
            return $value === [] ? [] : array_map(intval(...), $value);
        }
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        $map = [];
        foreach (explode(',', $value) as $pair) {
            $parts = array_map(trim(...), explode(':', $pair, 2));
            if ($parts[0] === '') {
                continue;
            }
            $key = ctype_digit($parts[0]) ? (int) $parts[0] : $parts[0];
            $map[$key] = isset($parts[1]) && $parts[1] !== '' ? (int) $parts[1] : PHP_INT_MAX;
        }

        return $map === [] ? $default : $map;
    }

    /**
     * "woff2, woff" or an array.
     *
     * @param list<string> $default
     * @return list<string>
     */
    private static function parseList(mixed $value, array $default): array
    {
        if (is_array($value)) {
            return array_values(array_map(strval(...), $value));
        }
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        return array_values(array_filter(array_map(trim(...), explode(',', strtolower($value)))));
    }
}
