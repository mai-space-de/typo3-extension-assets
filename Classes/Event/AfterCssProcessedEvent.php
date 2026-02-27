<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Event;

/**
 * Dispatched after a CSS asset has been processed (minified / read from file / captured
 * from inline Fluid content), but before it is written to disk or registered with
 * TYPO3's AssetCollector.
 *
 * Event listeners can:
 *  - Modify the CSS content via setProcessedCss()
 *  - Add a copyright header or environment-specific overrides
 *  - Completely replace the CSS (e.g., inject theme variables from database)
 *
 * Registration example in your site package's Services.yaml:
 *
 *   MyVendor\MySitePackage\EventListener\MyCssListener:
 *       tags:
 *           -   name: event.listener
 *               identifier: 'my-site-css-processor'
 *               event: Maispace\MaispaceAssets\Event\AfterCssProcessedEvent
 *
 * @see \Maispace\MaispaceAssets\Service\AssetProcessingService::handleCss()
 */
final class AfterCssProcessedEvent
{
    public function __construct(
        private string $identifier,
        private string $processedCss,
        private readonly array $viewHelperArguments,
    ) {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getProcessedCss(): string
    {
        return $this->processedCss;
    }

    /**
     * Replace the CSS content that will be registered with the AssetCollector.
     * The modified content is also stored in the cache, so subsequent requests
     * will serve the listener-modified version.
     */
    public function setProcessedCss(string $css): void
    {
        $this->processedCss = $css;
    }

    /**
     * The original ViewHelper argument array, e.g. ['identifier', 'src', 'priority',
     * 'minify', 'inline', 'deferred', 'media'].
     * These are read-only — use setProcessedCss() to influence the output.
     */
    public function getViewHelperArguments(): array
    {
        return $this->viewHelperArguments;
    }

    /**
     * Convenience: whether this asset is rendered as an inline <style> tag.
     */
    public function isInline(): bool
    {
        return (bool)($this->viewHelperArguments['inline'] ?? false);
    }

    /**
     * Convenience: whether this asset is placed in <head> (priority=true).
     */
    public function isPriority(): bool
    {
        return (bool)($this->viewHelperArguments['priority'] ?? false);
    }

    /**
     * Convenience: whether this asset is loaded deferred (media="print" swap).
     */
    public function isDeferred(): bool
    {
        return (bool)($this->viewHelperArguments['deferred'] ?? false);
    }
}
