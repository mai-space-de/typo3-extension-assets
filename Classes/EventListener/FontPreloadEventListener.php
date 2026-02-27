<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\EventListener;

use Maispace\MaispaceAssets\Registry\FontRegistry;
use TYPO3\CMS\Core\Page\Event\BeforeStylesheetsRenderingEvent;

/**
 * Emits `<link rel="preload" as="font">` header tags before the page `<head>` is rendered.
 *
 * Listens to `BeforeStylesheetsRenderingEvent`, which fires immediately before TYPO3
 * collects stylesheet tags. At this point `PageRenderer` is fully available, so the
 * font preload links are guaranteed to appear in `<head>` before any stylesheets.
 *
 * Site scoping
 * ============
 * The current site identifier is read from `$GLOBALS['TYPO3_REQUEST']` (populated by
 * TYPO3's site-resolver middleware). Fonts with a `sites` restriction are only emitted
 * when the current request's site matches.
 *
 * TypoScript kill-switch
 * ======================
 * Setting `plugin.tx_maispace_assets.fonts.preload = 0` in TypoScript suppresses all
 * font preload output globally. Useful for debugging or when fonts are handled elsewhere.
 *
 * @see FontRegistry
 */
final class FontPreloadEventListener
{
    public function __construct(
        private readonly FontRegistry $fontRegistry,
    ) {
    }

    public function __invoke(BeforeStylesheetsRenderingEvent $event): void
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;

        $siteIdentifier = null;
        if ($request !== null) {
            $siteIdentifier = $request->getAttribute('site')?->getIdentifier();
        }

        $globalPreloadEnabled = $this->resolveGlobalPreloadSetting($request);

        $this->fontRegistry->emitPreloadHeaders($siteIdentifier, $globalPreloadEnabled);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Read `plugin.tx_maispace_assets.fonts.preload` from the current TypoScript setup.
     * Returns true when not configured (preloading enabled by default).
     */
    private function resolveGlobalPreloadSetting(mixed $request): bool
    {
        if ($request === null) {
            return true;
        }

        /** @var \TYPO3\CMS\Core\TypoScript\FrontendTypoScript|null $fts */
        $fts = $request->getAttribute('frontend.typoscript');
        if ($fts === null) {
            return true;
        }

        $setup = $fts->getSetupArray();
        $setting = $setup['plugin.']['tx_maispace_assets.']['fonts.']['preload'] ?? null;

        // TypoScript values arrive as strings; treat '0' as disabled, anything else as enabled.
        return $setting !== '0';
    }
}
