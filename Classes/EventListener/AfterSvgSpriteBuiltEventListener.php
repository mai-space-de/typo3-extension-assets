<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\EventListener;

use Maispace\MaispaceAssets\Event\AfterSvgSpriteBuiltEvent;

/**
 * Example event listener for AfterSvgSpriteBuiltEvent.
 *
 * This listener demonstrates how to post-process the assembled SVG sprite before
 * it is cached and output into the page.
 *
 * HOW TO ACTIVATE IN YOUR SITE PACKAGE
 * =====================================
 * This listener is intentionally NOT registered by default. To activate it (or a
 * customised version of it), add the following to your site package's
 * Configuration/Services.yaml:
 *
 *   MyVendor\MySitePackage\EventListener\MySpriteListener:
 *       tags:
 *           -   name: event.listener
 *               identifier: 'my-site-svg-sprite'
 *               event: Maispace\MaispaceAssets\Event\AfterSvgSpriteBuiltEvent
 *
 * AVAILABLE EVENT API
 * ====================
 * $event->getSpriteHtml()           — the assembled SVG sprite HTML string
 * $event->setSpriteHtml($html)      — replace the sprite HTML before caching
 * $event->getRegisteredSymbolIds()  — array of all registered symbol ID strings
 *
 * USE CASES
 * ==========
 * - Add additional static symbols (e.g., brand icons) to every sprite without
 *   requiring a <ma:svgSprite register="..."> call in every template
 * - Transform the sprite HTML (e.g., strip dimensions, normalise attributes)
 * - Log which icons are included in the sprite for bundle analysis
 * - Prepend or append extra SVG definitions (e.g., <defs> gradients, filters)
 *
 * @see \Maispace\MaispaceAssets\Event\AfterSvgSpriteBuiltEvent
 * @see \Maispace\MaispaceAssets\Service\SvgSpriteService
 */
final class AfterSvgSpriteBuiltEventListener
{
    public function __invoke(AfterSvgSpriteBuiltEvent $event): void
    {
        // -----------------------------------------------------------------
        // Example A: Append a static brand symbol to every sprite regardless
        //            of which templates registered symbols.
        //
        // $brandSymbol = '<symbol id="icon-brand-logo" viewBox="0 0 200 60">'
        //     . '<text>My Brand</text>'
        //     . '</symbol>';
        //
        // $html = $event->getSpriteHtml();
        // // Insert before closing </svg>
        // $html = str_replace('</svg>', $brandSymbol . '</svg>', $html);
        // $event->setSpriteHtml($html);
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example B: Log which icons were included in this page's sprite.
        //
        // $ids = $event->getRegisteredSymbolIds();
        // error_log('[maispace_assets] SVG sprite built with ' . count($ids) . ' symbols: ' . implode(', ', $ids));
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example C: Wrap the sprite in a custom comment for developer tooling.
        //
        // $event->setSpriteHtml(
        //     "\n<!-- SVG Sprite: " . count($event->getRegisteredSymbolIds()) . " icons -->\n" .
        //     $event->getSpriteHtml()
        // );
        // -----------------------------------------------------------------
    }
}
