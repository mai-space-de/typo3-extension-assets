<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ExtensionConfiguration
{
    private const EXTENSION_KEY = 'mai_assets';

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

    public function __construct(Typo3ExtensionConfiguration $typo3ExtensionConfiguration)
    {
        $raw = [];
        try {
            $raw = $typo3ExtensionConfiguration->get(self::EXTENSION_KEY);
        } catch (\TYPO3\CMS\Core\Exception $e) {
            // Extension configuration not yet saved — use defaults
        }

        $this->enableScssProcessing = (bool)($raw['enableScssProcessing'] ?? true);
        $this->enableMinification = (bool)($raw['enableMinification'] ?? true);
        $this->enableCompression = (bool)($raw['enableCompression'] ?? true);
        $this->compressionLevel = (int)($raw['compressionLevel'] ?? 6);
        $this->enableBrotli = (bool)($raw['enableBrotli'] ?? true);
        $this->criticalThresholdByColPos = (array)($raw['criticalThresholdByColPos'] ?? [0 => 2, 1 => 0, 3 => 0]);
        $this->viewportBuckets = (array)($raw['viewportBuckets'] ?? ['mobile' => 768, 'tablet' => 1024, 'desktop' => PHP_INT_MAX]);
        $this->svgStripAttributes = (array)($raw['svgStripAttributes'] ?? ['id', 'class', 'style']);
        $this->fontPreloadFormats = (array)($raw['fontPreloadFormats'] ?? ['woff2']);
        $this->observerRootMargin = (string)($raw['observerRootMargin'] ?? '200px');
        $this->processingCacheLifetime = (int)($raw['processingCacheLifetime'] ?? 0);
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
}
