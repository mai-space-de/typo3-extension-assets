<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Event;

/**
 * Dispatched after a JS asset has been processed (minified / read from file / captured
 * from inline Fluid content), but before it is written to disk or registered with
 * TYPO3's AssetCollector.
 *
 * Event listeners can:
 *  - Modify the JS content via setProcessedJs()
 *  - Add copyright headers, polyfills, or environment constants
 *  - Completely replace the JS (e.g., inject feature-flag constants from TypoScript)
 *
 * Registration example in your site package's Services.yaml:
 *
 *   MyVendor\MySitePackage\EventListener\MyJsListener:
 *       tags:
 *           -   name: event.listener
 *               identifier: 'my-site-js-processor'
 *               event: Maispace\MaispaceAssets\Event\AfterJsProcessedEvent
 *
 * @see \Maispace\MaispaceAssets\Service\AssetProcessingService::handleJs()
 */
final class AfterJsProcessedEvent
{
    public function __construct(
        private string $identifier,
        private string $processedJs,
        /** @var array<string, mixed> */
        private readonly array $viewHelperArguments,
    ) {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getProcessedJs(): string
    {
        return $this->processedJs;
    }

    /**
     * Replace the JS content that will be registered with the AssetCollector.
     * The modified content is also stored in the cache.
     */
    public function setProcessedJs(string $js): void
    {
        $this->processedJs = $js;
    }

    /**
     * The original ViewHelper argument array, e.g. ['identifier', 'src', 'priority',
     * 'minify', 'defer', 'async', 'type'].
     */
    /**
     * @return array<string, mixed>
     */
    public function getViewHelperArguments(): array
    {
        return $this->viewHelperArguments;
    }

    /**
     * Convenience: whether the JS was provided inline (as Fluid tag body content).
     */
    public function isInlineCode(): bool
    {
        return empty($this->viewHelperArguments['src']);
    }

    /**
     * Convenience: whether this asset is placed in <head> (priority=true).
     */
    public function isPriority(): bool
    {
        return (bool)($this->viewHelperArguments['priority'] ?? false);
    }

    /**
     * Convenience: whether the <script> tag will have a defer attribute.
     */
    public function isDeferred(): bool
    {
        return (bool)($this->viewHelperArguments['defer'] ?? false);
    }
}
