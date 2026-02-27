<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\EventListener;

use Maispace\MaispaceAssets\Event\AfterSpriteBuiltEvent;

/**
 * Example event listener for AfterSpriteBuiltEvent.
 *
 * This listener demonstrates how to post-process the assembled SVG sprite XML
 * before it is cached and served from the HTTP endpoint.
 *
 * HOW TO ACTIVATE IN YOUR SITE PACKAGE
 * =====================================
 * This listener is intentionally NOT registered by default. Activate it (or your
 * own version) in your site package's Configuration/Services.yaml:
 *
 *   MyVendor\MySitePackage\EventListener\MySpritePostProcessor:
 *       tags:
 *           -   name: event.listener
 *               identifier: 'my-site-sprite-post-process'
 *               event: Maispace\MaispaceAssets\Event\AfterSpriteBuiltEvent
 *
 * AVAILABLE EVENT API
 * ====================
 * $event->getSpriteXml(): string           — the full SVG sprite XML document
 * $event->setSpriteXml(string $xml): void  — replace the XML before caching
 * $event->getRegisteredSymbolIds(): array  — list of all included symbol IDs
 *
 * USE CASES
 * ==========
 * - Append static symbols (e.g., brand logos) that are not contributed by any extension
 * - Inject SVG `<defs>` for reusable gradients, filters, or clip-paths
 * - Add an XML declaration for SVG files consumed outside a browser context
 * - Log the sprite contents for debugging or bundle-size auditing
 * - Prettify or minify the sprite XML based on environment
 *
 * @see AfterSpriteBuiltEvent
 * @see \Maispace\MaispaceAssets\Registry\SpriteIconRegistry
 */
final class AfterSpriteBuiltEventListener
{
    public function __invoke(AfterSpriteBuiltEvent $event): void
    {
        // -----------------------------------------------------------------
        // Example A: Append a static brand symbol that is not registered via
        //            SpriteIcons.php (e.g., an inline-defined symbol).
        //
        // $brandSymbol = '<symbol id="icon-brand" viewBox="0 0 200 60">'
        //     . '<rect width="200" height="60" fill="#e63946"/>'
        //     . '<text x="10" y="42" font-size="32" fill="#fff">Brand</text>'
        //     . '</symbol>';
        //
        // // Insert before the closing </svg> tag.
        // $xml = str_replace('</svg>', $brandSymbol . '</svg>', $event->getSpriteXml());
        // $event->setSpriteXml($xml);
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example B: Inject a <defs> block with reusable SVG gradients that
        //            symbols can reference via fill="url(#gradient-id)".
        //
        // $defs = '<defs>'
        //     . '<linearGradient id="grad-primary" x1="0" y1="0" x2="1" y2="1">'
        //     . '<stop offset="0%" stop-color="#e63946"/>'
        //     . '<stop offset="100%" stop-color="#c1121f"/>'
        //     . '</linearGradient>'
        //     . '</defs>';
        //
        // $xml = str_replace(
        //     '<svg xmlns',
        //     '<svg xmlns',       // keep opening tag
        //     $event->getSpriteXml()
        // );
        // // Insert <defs> immediately after the opening <svg> tag.
        // $xml = preg_replace('/<svg([^>]*)>/', '<svg$1>' . $defs, $event->getSpriteXml());
        // $event->setSpriteXml($xml);
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example C: Log the symbol inventory for bundle-size auditing.
        //
        // $ids      = $event->getRegisteredSymbolIds();
        // $byteSize = strlen($event->getSpriteXml());
        // error_log(sprintf(
        //     '[maispace_assets] Sprite built: %d symbols, %d bytes. IDs: %s',
        //     count($ids),
        //     $byteSize,
        //     implode(', ', $ids),
        // ));
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example D: Strip whitespace from the sprite XML in production
        //            for a slightly smaller HTTP response.
        //
        // $minified = preg_replace('/\s+/', ' ', $event->getSpriteXml());
        // $minified = preg_replace('/> </', '><', $minified);
        // $event->setSpriteXml(trim($minified));
        // -----------------------------------------------------------------
    }
}
