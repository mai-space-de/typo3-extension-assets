<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\EventListener;

use Maispace\MaispaceAssets\Event\BeforeSpriteSymbolRegisteredEvent;

/**
 * Example event listener for BeforeSpriteSymbolRegisteredEvent.
 *
 * This listener demonstrates how to intercept SVG symbol registrations during
 * the auto-discovery phase — before any symbol is stored in SpriteIconRegistry.
 *
 * HOW TO ACTIVATE IN YOUR SITE PACKAGE
 * =====================================
 * This listener is intentionally NOT registered by default. Activate it (or your
 * own version) in your site package's Configuration/Services.yaml:
 *
 *   MyVendor\MySitePackage\EventListener\MyIconFilterListener:
 *       tags:
 *           -   name: event.listener
 *               identifier: 'my-site-icon-filter'
 *               event: Maispace\MaispaceAssets\Event\BeforeSpriteSymbolRegisteredEvent
 *
 * AVAILABLE EVENT API
 * ====================
 * $event->getSymbolId()            — the symbol ID (array key from SpriteIcons.php)
 * $event->setSymbolId(string)      — rename the symbol
 * $event->getConfig()              — ['src' => 'EXT:...', ...]
 * $event->setConfig(array)         — replace src or other config values
 * $event->getSourceExtensionKey()  — which extension registered this symbol
 * $event->skip()                   — exclude this symbol from the sprite entirely
 * $event->isSkipped(): bool        — check current skip state
 *
 * USE CASES
 * ==========
 * - Filter out icons from specific vendor extensions you don't need
 * - Rename icons from third-party naming conventions to your project's convention
 * - Redirect an icon's src to an overriding SVG in your site package
 * - Log all registrations during development for auditing
 *
 * @see BeforeSpriteSymbolRegisteredEvent
 * @see \Maispace\MaispaceAssets\Registry\SpriteIconRegistry
 */
final class BeforeSpriteSymbolRegisteredEventListener
{
    public function __invoke(BeforeSpriteSymbolRegisteredEvent $event): void
    {
        // -----------------------------------------------------------------
        // Example A: Skip all icons from a specific extension.
        //            Useful if a vendor extension registers icons you don't want.
        //
        // if ($event->getSourceExtensionKey() === 'vendor_extension') {
        //     $event->skip();
        //     return;
        // }
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example B: Rename an icon from a vendor convention to your own.
        //            Update all <mai:svgSprite use="..."> calls accordingly.
        //
        // $renames = [
        //     'vendor-arrow-right' => 'icon-arrow',
        //     'vendor-x-mark'      => 'icon-close',
        // ];
        // if (isset($renames[$event->getSymbolId()])) {
        //     $event->setSymbolId($renames[$event->getSymbolId()]);
        // }
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example C: Override the SVG source for a specific icon.
        //            Allows your site package to replace a vendor icon with
        //            your own design without touching the vendor extension.
        //
        // if ($event->getSymbolId() === 'icon-logo') {
        //     $event->setConfig([
        //         'src' => 'EXT:my_sitepackage/Resources/Public/Icons/logo-custom.svg',
        //     ]);
        // }
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example D: Log all icon registrations during development.
        //
        // error_log(sprintf(
        //     '[maispace_assets] Registering symbol "%s" from "%s": %s',
        //     $event->getSymbolId(),
        //     $event->getSourceExtensionKey(),
        //     $event->getConfig()['src'] ?? '(no src)',
        // ));
        // -----------------------------------------------------------------
    }
}
