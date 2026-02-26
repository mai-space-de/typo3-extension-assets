<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\EventListener;

use Maispace\MaispaceAssets\Event\AfterScssCompiledEvent;

/**
 * Example event listener for AfterScssCompiledEvent.
 *
 * This listener demonstrates how to post-process compiled SCSS output before it
 * is cached or registered with the AssetCollector.
 *
 * HOW TO ACTIVATE IN YOUR SITE PACKAGE
 * =====================================
 * This listener is intentionally NOT registered by default. To activate it (or a
 * customised version of it), add the following to your site package's
 * Configuration/Services.yaml:
 *
 *   MyVendor\MySitePackage\EventListener\MyScssListener:
 *       tags:
 *           -   name: event.listener
 *               identifier: 'my-site-scss-processor'
 *               event: Maispace\MaispaceAssets\Event\AfterScssCompiledEvent
 *
 * AVAILABLE EVENT API
 * ====================
 * $event->getIdentifier()          — the asset identifier string
 * $event->getOriginalScss()        — the raw SCSS that was passed to the compiler (read-only)
 * $event->getCompiledCss()         — the compiled CSS output
 * $event->setCompiledCss($css)     — replace the CSS before caching/registration
 * $event->getViewHelperArguments() — the raw ViewHelper argument array
 * $event->isInline()               — true when rendering as <style> tag
 *
 * USE CASES
 * ==========
 * - Inject dynamic CSS custom properties (e.g., theme colours from a database record)
 *   after compilation, so they override variables set in SCSS
 * - Add autoprefixer-like vendor prefixes (e.g., via a PHP CSS parsing library)
 * - Validate that the compiled output does not exceed a size budget
 * - Log SCSS compilation metrics (input size, output size, identifier)
 *
 * @see \Maispace\MaispaceAssets\Event\AfterScssCompiledEvent
 * @see \Maispace\MaispaceAssets\Service\ScssCompilerService
 */
final class AfterScssCompiledEventListener
{
    public function __invoke(AfterScssCompiledEvent $event): void
    {
        // -----------------------------------------------------------------
        // Example A: Inject CSS custom properties from a configuration source
        //            (e.g., a database-stored theme colour palette).
        //
        // $primaryColor   = $this->getThemeColor('primary');   // your logic
        // $secondaryColor = $this->getThemeColor('secondary');
        //
        // $cssVars = ":root { --color-primary: {$primaryColor}; --color-secondary: {$secondaryColor}; }\n";
        //
        // $event->setCompiledCss($cssVars . $event->getCompiledCss());
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example B: Log SCSS compilation size for performance budgeting.
        //
        // $inputSize  = strlen($event->getOriginalScss());
        // $outputSize = strlen($event->getCompiledCss());
        // error_log(sprintf(
        //     '[maispace_assets] SCSS compiled "%s": %d bytes SCSS → %d bytes CSS',
        //     $event->getIdentifier(),
        //     $inputSize,
        //     $outputSize,
        // ));
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example C: Append a print stylesheet reset to every compiled SCSS.
        //
        // if (!$event->isInline()) {
        //     $event->setCompiledCss(
        //         $event->getCompiledCss() . "\n@media print { * { color: black !important; } }"
        //     );
        // }
        // -----------------------------------------------------------------
    }
}
