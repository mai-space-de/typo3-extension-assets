<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\Event;

/**
 * Dispatched after critical CSS and JS have been extracted for a page and viewport,
 * but before the result is stored in the TYPO3 caching framework cache.
 *
 * Listeners can inspect and modify the extracted CSS/JS to:
 *  - Add vendor prefixes or custom property fallbacks.
 *  - Remove rules that should never be inlined (e.g. print styles).
 *  - Completely replace the critical content with a hand-curated version.
 *
 * Registration example (Services.yaml in your site package):
 *
 *   MyVendor\MySitePackage\EventListener\MyCriticalCssListener:
 *       tags:
 *           -   name: event.listener
 *               identifier: 'my-critical-css-modifier'
 *               event: Maispace\MaispaceAssets\Event\AfterCriticalCssExtractedEvent
 *
 * @see \Maispace\MaispaceAssets\Service\CriticalAssetService::extractForPage()
 */
final class AfterCriticalCssExtractedEvent
{
    public function __construct(
        private readonly int $pageUid,
        private readonly string $viewport,
        private string $criticalCss,
        private string $criticalJs,
    ) {
    }

    /**
     * The TYPO3 page UID for which critical assets were extracted.
     */
    public function getPageUid(): int
    {
        return $this->pageUid;
    }

    /**
     * The viewport name, e.g. "mobile" or "desktop".
     */
    public function getViewport(): string
    {
        return $this->viewport;
    }

    /**
     * The extracted critical CSS string (above-fold rules only).
     */
    public function getCriticalCss(): string
    {
        return $this->criticalCss;
    }

    /**
     * Replace the extracted critical CSS before it is stored in the cache.
     * Pass an empty string to suppress caching for this viewport.
     */
    public function setCriticalCss(string $css): void
    {
        $this->criticalCss = $css;
    }

    /**
     * The extracted critical JS string (synchronous inline scripts only).
     */
    public function getCriticalJs(): string
    {
        return $this->criticalJs;
    }

    /**
     * Replace the extracted critical JS before it is stored in the cache.
     * Pass an empty string to suppress caching for this viewport.
     */
    public function setCriticalJs(string $js): void
    {
        $this->criticalJs = $js;
    }
}
