<?php

declare(strict_types=1);

/**
 * SpriteIcons.php — SVG Sprite Symbol Registration
 * =================================================
 * This file registers SVG symbols that will be included in the maispace_assets
 * SVG sprite, served at the configured route (default: /maispace/sprite.svg).
 *
 * HOW AUTO-DISCOVERY WORKS
 * ========================
 * SpriteIconRegistry scans every loaded TYPO3 extension for this file at boot time.
 * You do not need to register anything in ext_localconf.php or Services.yaml.
 * Simply drop this file in your extension's Configuration/ directory.
 *
 * FILE FORMAT
 * ===========
 * Return a flat array where:
 *   - The KEY    is the symbol ID (used as `<symbol id="...">` and `<use href="...#id">`)
 *   - The VALUE  is a config array with:
 *       'src'   (string, required) — EXT: path or absolute path to the .svg file
 *       'sites' (array,  optional) — list of TYPO3 site identifiers; omit for all sites
 *
 * MULTI-SITE SCOPING
 * ==================
 * Use the 'sites' key to restrict a symbol to specific TYPO3 sites in the same instance.
 * This ensures each site's sprite contains only its own icons — no wasted bandwidth.
 *
 *   'icon-brand-a-logo' => [
 *       'src'   => 'EXT:brand_a/Resources/Public/Icons/logo.svg',
 *       'sites' => ['brand-a'],   // only included in the sprite for site "brand-a"
 *   ],
 *
 * Symbols without a 'sites' key are included on all sites (global/shared icons).
 *
 * SYMBOL ID NAMING CONVENTION
 * ============================
 * Use the prefix "icon-" (the default TypoScript svgSprite.symbolIdPrefix) for
 * consistency with the ViewHelper convention:
 *   <mai:svgSprite use="icon-arrow" ... />
 *
 * OVERRIDE BEHAVIOUR
 * ==================
 * If two extensions register the same symbol ID, the extension loaded later wins.
 * TYPO3 extension loading order follows the `ext_emconf.php` dependency declarations.
 * Use this to override icons from base/vendor extensions in your site package.
 *
 * USING REGISTERED ICONS IN TEMPLATES
 * =====================================
 * Once registered, reference any icon via:
 *
 *   <mai:svgSprite use="icon-arrow" width="24" height="24" class="icon" />
 *
 * No register/render calls needed — the sprite is served automatically from the API.
 *
 * EVENT HOOKS
 * ===========
 * Listen to these events to modify or filter registrations:
 *   - Maispace\MaispaceAssets\Event\BeforeSpriteSymbolRegisteredEvent
 *       → rename, modify src, or veto individual symbols
 *   - Maispace\MaispaceAssets\Event\AfterSpriteBuiltEvent
 *       → post-process the full sprite XML before it is cached
 *
 * ============================================================================
 * This file is the maispace_assets extension's own sample registration.
 * It registers a small set of universally useful UI icons.
 * Site packages should register their own icons in their own SpriteIcons.php.
 * ============================================================================
 */
return [

    // -------------------------------------------------------------------------
    // The following entries are EXAMPLES.
    // Replace with your actual icon files or remove entries you do not need.
    // These paths assume the icons exist in your extension — adjust accordingly.
    // -------------------------------------------------------------------------

    /*
    'icon-arrow-right' => [
        'src' => 'EXT:maispace_assets/Resources/Public/Icons/arrow-right.svg',
    ],

    'icon-arrow-left' => [
        'src' => 'EXT:maispace_assets/Resources/Public/Icons/arrow-left.svg',
    ],

    'icon-close' => [
        'src' => 'EXT:maispace_assets/Resources/Public/Icons/close.svg',
    ],

    'icon-menu' => [
        'src' => 'EXT:maispace_assets/Resources/Public/Icons/menu.svg',
    ],

    'icon-search' => [
        'src' => 'EXT:maispace_assets/Resources/Public/Icons/search.svg',
    ],

    'icon-external-link' => [
        'src' => 'EXT:maispace_assets/Resources/Public/Icons/external-link.svg',
    ],

    'icon-check' => [
        'src' => 'EXT:maispace_assets/Resources/Public/Icons/check.svg',
    ],

    'icon-warning' => [
        'src' => 'EXT:maispace_assets/Resources/Public/Icons/warning.svg',
    ],
    */

];
