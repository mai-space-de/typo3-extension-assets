<?php

declare(strict_types=1);

/**
 * Fonts.php — Font Preload Registration
 * ======================================
 * This file registers web fonts that will receive `<link rel="preload" as="font">`
 * tags in the page `<head>`, improving perceived load performance by hinting the
 * browser to fetch fonts before they are discovered in CSS.
 *
 * HOW AUTO-DISCOVERY WORKS
 * ========================
 * FontRegistry scans every loaded TYPO3 extension for this file at boot time.
 * You do not need to register anything in ext_localconf.php or Services.yaml.
 * Simply drop this file in your extension's Configuration/ directory.
 *
 * FILE FORMAT
 * ===========
 * Return a flat array where:
 *   - The KEY    is a unique font identifier (used for deduplication only)
 *   - The VALUE  is a config array with:
 *       'src'     (string, required)  — EXT: path or absolute path to the font file
 *       'type'    (string, optional)  — MIME type; auto-detected from extension when omitted
 *                                       Supported: font/woff2, font/woff, font/ttf, font/otf
 *       'preload' (bool,   optional)  — default true; set false to register without preloading
 *       'sites'   (array,  optional)  — list of TYPO3 site identifiers; omit for all sites
 *
 * MULTI-SITE SCOPING
 * ==================
 * Use the 'sites' key to restrict a font to one or more TYPO3 sites in the same instance.
 * The site identifier matches the folder name under config/sites/{identifier}/.
 *
 *   'brand-a-regular' => [
 *       'src'   => 'EXT:brand_a/Resources/Public/Fonts/Regular.woff2',
 *       'sites' => ['brand-a'],   // only preloaded on the "brand-a" site
 *   ],
 *
 * Fonts without a 'sites' key are included on all sites.
 *
 * OVERRIDE BEHAVIOUR
 * ==================
 * If two extensions register the same key, the extension loaded later wins.
 * Use this to override vendor fonts in your site package.
 *
 * PRELOAD DISABLED
 * ================
 * Set 'preload' => false to register a font without emitting a preload tag.
 * This is useful when you want the font on record but prefer lazy loading,
 * or when the font is already handled by a CDN / HTTP/2 push header.
 *
 * GLOBAL KILL-SWITCH
 * ==================
 * Set in TypoScript to disable all font preloading site-wide:
 *   plugin.tx_maispace_assets.fonts.preload = 0
 *
 * ============================================================================
 * This file is the maispace_assets extension's own sample registration.
 * Site packages should register their own fonts in their own Fonts.php.
 * ============================================================================
 */
return [

    // -------------------------------------------------------------------------
    // The following entries are EXAMPLES.
    // Replace with your actual font files or remove entries you do not need.
    // These paths assume the fonts exist in your extension — adjust accordingly.
    // -------------------------------------------------------------------------

    /*

    // Shared font — available on all sites
    'my-font-regular' => [
        'src'  => 'EXT:my_sitepackage/Resources/Public/Fonts/MyFont-Regular.woff2',
        // 'type' is auto-detected as font/woff2 from the .woff2 extension
    ],

    'my-font-bold' => [
        'src'     => 'EXT:my_sitepackage/Resources/Public/Fonts/MyFont-Bold.woff2',
        'preload' => false,   // loaded normally, no preload hint
    ],

    // Site-specific fonts for a multi-brand TYPO3 instance
    'brand-a-regular' => [
        'src'   => 'EXT:brand_a/Resources/Public/Fonts/BrandA-Regular.woff2',
        'sites' => ['brand-a'],
    ],

    'brand-a-bold' => [
        'src'   => 'EXT:brand_a/Resources/Public/Fonts/BrandA-Bold.woff2',
        'sites' => ['brand-a'],
    ],

    'brand-b-regular' => [
        'src'   => 'EXT:brand_b/Resources/Public/Fonts/BrandB-Regular.woff2',
        'sites' => ['brand-b', 'brand-b-staging'],  // multiple site identifiers allowed
    ],

    */

];
