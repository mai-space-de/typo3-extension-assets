<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\EventListener;

use Maispace\MaispaceAssets\Event\AfterCssProcessedEvent;

/**
 * Example event listener for AfterCssProcessedEvent.
 *
 * This listener demonstrates how to post-process CSS assets after they have been
 * minified but before they are written to disk and registered with the AssetCollector.
 *
 * HOW TO ACTIVATE IN YOUR SITE PACKAGE
 * =====================================
 * This listener is intentionally NOT registered by default. To activate it (or a
 * customised version of it), add the following to your site package's
 * Configuration/Services.yaml:
 *
 *   MyVendor\MySitePackage\EventListener\MyCssListener:
 *       tags:
 *           -   name: event.listener
 *               identifier: 'my-site-css-processor'
 *               event: Maispace\MaispaceAssets\Event\AfterCssProcessedEvent
 *
 * AVAILABLE EVENT API
 * ====================
 * $event->getIdentifier()          — the asset identifier string
 * $event->getProcessedCss()        — the (potentially minified) CSS string
 * $event->setProcessedCss($css)    — replace the CSS before caching/registration
 * $event->getViewHelperArguments() — the raw ViewHelper argument array
 * $event->isInline()               — true when rendering as <style> tag
 * $event->isPriority()             — true when placed in <head>
 * $event->isDeferred()             — true when using media="print" deferred loading
 *
 * USE CASES
 * ==========
 * - Prepend a copyright / license comment to every CSS file
 * - Inject CSS custom properties from a database record (theme colours)
 * - Log asset processing for debugging or auditing
 * - Apply additional transformations (e.g., vendor prefixing) via a post-processor
 *
 * @see AfterCssProcessedEvent
 */
final class AfterCssProcessedEventListener
{
    public function __invoke(AfterCssProcessedEvent $event): void
    {
        // -----------------------------------------------------------------
        // Example A: Prepend a copyright comment to every CSS asset.
        //
        // $event->setProcessedCss(
        //     '/* (c) ' . date('Y') . ' My Company — All rights reserved */ ' .
        //     $event->getProcessedCss()
        // );
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example B: Inject CSS custom properties from TypoScript / database
        //            only for assets that are rendered inline in <head>.
        //
        // if ($event->isInline() && $event->isPriority()) {
        //     $themeColor = $this->fetchThemeColorFromDatabase(); // your logic here
        //     $event->setProcessedCss(
        //         ':root { --color-primary: ' . $themeColor . '; } ' .
        //         $event->getProcessedCss()
        //     );
        // }
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example C: Log the identifier of every processed CSS asset.
        //
        // error_log('[maispace_assets] CSS processed: ' . $event->getIdentifier());
        // -----------------------------------------------------------------
    }
}
