.. include:: /Includes.rst.txt

.. _viewhelpers:

===========
ViewHelpers
===========

All ViewHelpers are available under the globally registered namespace ``mai``.
No ``{namespace}`` declaration is needed in your Fluid templates.

.. _viewhelper-css:

mai:css
=======

Include a CSS asset from a file or inline Fluid content.

.. code-block:: html

    <!-- From a file -->
    <mai:css src="EXT:my_ext/Resources/Public/Css/app.css" />

    <!-- From an external CDN (passed through directly, no minification) -->
    <mai:css src="https://fonts.googleapis.com/css2?family=Inter:wght@400;700" />

    <!-- External CDN with pre-computed SRI hash -->
    <mai:css src="https://cdn.example.com/styles.css"
             integrityValue="sha384-abc123..." crossorigin="anonymous" />

    <!-- Inline CSS (auto-identifier from content hash) -->
    <mai:css identifier="hero-styles">
        .hero { background: #e63946; color: #fff; padding: 4rem; }
    </mai:css>

    <!-- Critical CSS inlined in <head> -->
    <mai:css identifier="critical" priority="true" inline="true" minify="true">
        body { margin: 0; font-family: sans-serif; }
        :root { --color-primary: #e63946; }
    </mai:css>

    <!-- Non-critical CSS loaded deferred (media="print" swap) -->
    <mai:css src="EXT:theme/Resources/Public/Css/non-critical.css" deferred="true" />

    <!-- Standard <link> in footer, no deferral -->
    <mai:css src="EXT:theme/Resources/Public/Css/layout.css" deferred="false" />

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 20 10 10 15 45

    * - Argument
      - Type
      - Required
      - Default
      - Description
    * - ``identifier``
      - string
      - No
      - auto (md5)
      - Unique key for TYPO3's AssetCollector. Auto-generated from a hash of the
        source content when omitted. Provide an explicit value to reference this
        asset from multiple templates without duplication.
    * - ``src``
      - string
      - No
      - null
      - EXT: path (e.g. ``EXT:my_ext/Resources/Public/Css/app.css``), absolute
        path, or external URL (``https://...``). When an external URL is provided
        it is registered directly with AssetCollector — no file read, minification,
        or cache write occurs. When ``src`` is provided, inline child content is ignored.
    * - ``priority``
      - bool
      - No
      - ``false``
      - Place the asset in ``<head>`` when ``true``. ``false`` places it in the footer.
    * - ``minify``
      - bool
      - No
      - null
      - Override the TypoScript ``css.minify`` setting for this single asset.
        ``null`` inherits the global setting. Has no effect on external URLs.
    * - ``inline``
      - bool
      - No
      - ``false``
      - Render the CSS as an inline ``<style>`` tag instead of a ``<link>`` to
        an external file. Useful for critical above-the-fold CSS.
    * - ``deferred``
      - bool
      - No
      - null
      - Load the CSS non-blocking via ``media="print"`` onload swap. A ``<noscript>``
        fallback is appended automatically. ``null`` inherits ``css.deferred`` from TypoScript.
        Has no effect on external URLs (registered as-is).
    * - ``media``
      - string
      - No
      - ``all``
      - The ``media`` attribute for the generated ``<link>`` tag.
    * - ``nonce``
      - string
      - No
      - null
      - CSP nonce for the inline ``<style>`` tag. Only applied when ``inline="true"``.
        **Auto-detected**: when TYPO3's built-in CSP is enabled, the nonce is read from
        the request automatically — no argument needed. Pass an explicit value only to
        override the auto-detected nonce.
    * - ``integrity``
      - bool
      - No
      - null
      - When ``true``, automatically compute a SHA-384 SRI hash of the processed CSS
        and add an ``integrity`` attribute to the generated ``<link>`` tag.
        Only applied for local file assets (not inline, not external URLs). Browsers
        refuse to load the file if the hash does not match.
    * - ``integrityValue``
      - string
      - No
      - null
      - Pre-computed SRI hash string (e.g. ``sha384-abc123...``) to use as the
        ``integrity`` attribute. Intended for **external CDN assets** where a hash
        cannot be computed at render time. Takes precedence over ``integrity="true"``.
    * - ``crossorigin``
      - string
      - No
      - null
      - Value for the ``crossorigin`` attribute added alongside ``integrity`` or
        ``integrityValue``. Defaults to ``"anonymous"`` when ``integrity`` is enabled.

.. _viewhelper-js:

mai:js
======

Include a JavaScript asset from a file or inline Fluid content.

.. code-block:: html

    <!-- External file (deferred by default per TypoScript) -->
    <mai:js src="EXT:theme/Resources/Public/JavaScript/app.js" />

    <!-- ES module -->
    <mai:js src="EXT:theme/Resources/Public/JavaScript/app.js" type="module" />

    <!-- Legacy fallback bundle (only loaded by browsers that don't support modules) -->
    <mai:js src="EXT:theme/Resources/Public/JavaScript/legacy.js" nomodule="true" />

    <!-- External CDN script (passed through directly, no minification) -->
    <mai:js src="https://cdn.example.com/library.js" />

    <!-- External CDN with pre-computed SRI hash -->
    <mai:js src="https://cdn.example.com/library.js"
            integrityValue="sha384-xyz789..." crossorigin="anonymous" />

    <!-- Import map (synchronous inline JSON — never minified or deferred) -->
    <mai:js type="importmap">
        {"imports":{"lodash":"/vendor/lodash-es/lodash.js"}}
    </mai:js>

    <!-- Async (loaded in parallel, executed immediately when ready) -->
    <mai:js src="EXT:theme/Resources/Public/JavaScript/analytics.js" async="true" />

    <!-- Critical JS in <head>, no defer -->
    <mai:js src="EXT:theme/Resources/Public/JavaScript/polyfills.js"
           priority="true" defer="false" />

    <!-- Inline JS -->
    <mai:js identifier="page-init">
        document.addEventListener('DOMContentLoaded', function() {
            document.body.classList.add('js-ready');
        });
    </mai:js>

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 20 10 10 15 45

    * - Argument
      - Type
      - Required
      - Default
      - Description
    * - ``identifier``
      - string
      - No
      - auto (md5)
      - Unique key for TYPO3's AssetCollector.
    * - ``src``
      - string
      - No
      - null
      - EXT: path, absolute path, or external URL (``https://...``) to a JS file.
        External URLs are passed through to AssetCollector without file read,
        minification, or cache write.
    * - ``priority``
      - bool
      - No
      - ``false``
      - Place in ``<head>`` when ``true``.
    * - ``minify``
      - bool
      - No
      - null
      - Override the TypoScript ``js.minify`` setting. Has no effect on external URLs.
    * - ``defer``
      - bool
      - No
      - null
      - Add the ``defer`` attribute. ``null`` inherits ``js.defer`` from TypoScript (default: 1).
        Automatically disabled when ``type="importmap"`` or ``nomodule="true"``.
    * - ``async``
      - bool
      - No
      - ``false``
      - Add the ``async`` attribute. The script is fetched in parallel and executed
        as soon as available. Automatically disabled when ``nomodule="true"``.
    * - ``type``
      - string
      - No
      - null
      - The ``type`` attribute. Use ``"module"`` for ES modules or ``"importmap"`` for
        inline import maps.

        **``type="importmap"`` special behaviour:** the content is treated as inline JSON,
        never minified, always placed in ``<head>`` (``priority=true`` forced), and
        ``defer`` / ``async`` are suppressed. This matches the HTML spec requirement
        that import maps must be parsed synchronously before any module scripts run.
    * - ``nomodule``
      - bool
      - No
      - ``false``
      - Add the ``nomodule`` attribute. When ``true``, the script is only executed by
        browsers that do not support ES modules — the classic differential loading
        pattern. ``defer`` and ``async`` are suppressed automatically because
        ``nomodule`` scripts must be loaded synchronously.
    * - ``nonce``
      - string
      - No
      - null
      - CSP nonce for the inline ``<script>`` tag. Only applied for inline JS (no ``src`` set).
        **Auto-detected**: when TYPO3's built-in CSP is enabled, the nonce is read from
        the request automatically — no argument needed. Pass an explicit value only to
        override the auto-detected nonce.
    * - ``integrity``
      - bool
      - No
      - null
      - When ``true``, automatically compute a SHA-384 SRI hash of the processed JS
        and add an ``integrity`` attribute to the ``<script>`` tag.
        Only applied for local file assets. Browsers refuse to execute the script
        if the hash does not match.
    * - ``integrityValue``
      - string
      - No
      - null
      - Pre-computed SRI hash string (e.g. ``sha384-abc123...``) to use as the
        ``integrity`` attribute. Intended for **external CDN scripts** where a hash
        cannot be computed at render time. Takes precedence over ``integrity="true"``.
    * - ``crossorigin``
      - string
      - No
      - null
      - Value for the ``crossorigin`` attribute added alongside ``integrity`` or
        ``integrityValue``. Defaults to ``"anonymous"`` when ``integrity`` is enabled.

.. _viewhelper-scss:

mai:scss
========

Compile SCSS to CSS server-side and include the result as a CSS asset.
No Node.js or build pipeline is required.

.. code-block:: html

    <!-- SCSS from file (cache auto-invalidated on file change) -->
    <mai:scss src="EXT:theme/Resources/Private/Scss/main.scss" />

    <!-- SCSS file with additional @import paths -->
    <mai:scss src="EXT:theme/Resources/Private/Scss/main.scss"
             importPaths="EXT:theme/Resources/Private/Scss/Partials,EXT:base/Resources/Private/Scss" />

    <!-- SRI integrity hash on the compiled <link> -->
    <mai:scss src="EXT:theme/Resources/Private/Scss/main.scss" integrity="true" />

    <!-- Inline SCSS (identifier derived from content hash) -->
    <mai:scss identifier="hero-theme">
        $primary: #e63946;
        $spacing: 1.5rem;
        .hero { background: $primary; padding: $spacing; color: white; }
    </mai:scss>

    <!-- Critical SCSS inlined in <head> with CSP nonce (auto-detected from TYPO3 request) -->
    <mai:scss identifier="critical-reset" priority="true" inline="true">
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; }
    </mai:scss>

    <!-- Compiled SCSS loaded deferred -->
    <mai:scss src="EXT:theme/Resources/Private/Scss/non-critical.scss" deferred="true" />

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 20 10 10 15 45

    * - Argument
      - Type
      - Required
      - Default
      - Description
    * - ``identifier``
      - string
      - No
      - auto (md5)
      - Unique key for caching and the AssetCollector.
    * - ``src``
      - string
      - No
      - null
      - EXT: path or absolute path to a ``.scss`` file.
    * - ``priority``
      - bool
      - No
      - ``false``
      - Place compiled CSS in ``<head>`` when ``true``.
    * - ``minify``
      - bool
      - No
      - null
      - Use scssphp's ``OutputStyle::COMPRESSED``. ``null`` inherits ``scss.minify``.
    * - ``inline``
      - bool
      - No
      - ``false``
      - Render compiled CSS as an inline ``<style>`` tag.
    * - ``deferred``
      - bool
      - No
      - null
      - Load compiled CSS non-blocking. ``null`` inherits ``css.deferred``.
    * - ``media``
      - string
      - No
      - ``all``
      - The ``media`` attribute for the generated ``<link>`` tag.
    * - ``importPaths``
      - string
      - No
      - null
      - Comma-separated list of additional import paths. Supports ``EXT:`` notation.
    * - ``nonce``
      - string
      - No
      - null
      - CSP nonce for the inline ``<style>`` tag. Only applied when ``inline="true"``.
        When TYPO3's built-in Content Security Policy is active, the nonce is
        auto-detected from the request — pass an explicit value only to override it.
    * - ``integrity``
      - bool
      - No
      - ``false``
      - Compute and add an ``integrity="sha384-..."`` SRI attribute to the generated
        ``<link>`` tag. Only applies to file-based (non-inline) output.
    * - ``crossorigin``
      - string
      - No
      - null
      - ``crossorigin`` attribute value when ``integrity`` is enabled.
        Defaults to ``"anonymous"`` when omitted.

.. _viewhelper-svgsprite:

mai:svgSprite
=============

Output an ``<svg><use>`` reference to a symbol from the centrally served SVG sprite.

The sprite is assembled automatically from all ``Configuration/SpriteIcons.php`` files
found across loaded extensions and served from a dedicated HTTP endpoint
(default: ``/maispace/sprite.svg``). The endpoint response is browser-cacheable with
``Cache-Control: public, max-age=31536000, immutable`` and supports conditional requests
via ETag for efficient cache revalidation.

.. seealso::

    See :ref:`configuration-spriteiconregistry` for how to register symbols from your
    own extension and :ref:`events` for the ``BeforeSpriteSymbolRegisteredEvent`` and
    ``AfterSpriteBuiltEvent`` hooks.

Usage
-----

.. code-block:: html

    <!-- Decorative icon (aria-hidden="true" added automatically) -->
    <mai:svgSprite use="icon-arrow" width="24" height="24" class="icon icon--arrow" />

    <!-- Meaningful icon with accessible label (role="img" added automatically) -->
    <mai:svgSprite use="icon-close" aria-label="Close dialog" width="20" height="20" />

    <!-- Icon with <title> for screen reader context -->
    <mai:svgSprite use="icon-external" title="Opens in a new window" class="icon" />

    <!-- Override the sprite URL for a multi-sprite setup -->
    <mai:svgSprite use="icon-logo" src="/brand/sprite.svg" width="120" height="40" />

Generated Output
----------------

For ``<mai:svgSprite use="icon-arrow" width="24" height="24" />``:

.. code-block:: html

    <svg width="24" height="24" aria-hidden="true">
        <use href="/maispace/sprite.svg#icon-arrow"></use>
    </svg>

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 20 10 10 15 45

    * - Argument
      - Type
      - Required
      - Default
      - Description
    * - ``use``
      - string
      - **Yes**
      - —
      - Symbol ID to reference. Must match a key defined in a ``SpriteIcons.php`` file
        (after any ``BeforeSpriteSymbolRegisteredEvent`` renaming).
    * - ``src``
      - string
      - No
      - null
      - Override the sprite URL. When ``null``, the URL is read from the TypoScript
        setting ``plugin.tx_maispace_assets.svgSprite.routePath`` (default:
        ``/maispace/sprite.svg``). Useful for multi-sprite setups.
    * - ``class``
      - string
      - No
      - null
      - CSS class(es) for the ``<svg>`` element.
    * - ``width``
      - string
      - No
      - null
      - ``width`` attribute for the ``<svg>`` element.
    * - ``height``
      - string
      - No
      - null
      - ``height`` attribute for the ``<svg>`` element.
    * - ``aria-hidden``
      - string
      - No
      - ``true``
      - Defaults to ``"true"`` for decorative icons. Set to ``"false"`` together with
        ``aria-label`` to make the icon meaningful to screen readers.
    * - ``aria-label``
      - string
      - No
      - null
      - Accessible label. When set, ``role="img"`` is added automatically and
        ``aria-hidden`` is omitted.
    * - ``title``
      - string
      - No
      - null
      - ``<title>`` element inside the ``<svg>`` for additional screen reader context.

Registering Symbols
-------------------

Symbols are not registered via the ViewHelper. Instead, create
``Configuration/SpriteIcons.php`` in your extension and return an array of symbol
definitions. The registry auto-discovers this file across all loaded extensions:

.. code-block:: php

    <?php
    // EXT:my_sitepackage/Configuration/SpriteIcons.php
    declare(strict_types=1);

    return [
        // Global icon — included on all sites
        'icon-arrow' => [
            'src' => 'EXT:my_sitepackage/Resources/Public/Icons/arrow.svg',
        ],
        'icon-close' => [
            'src' => 'EXT:my_sitepackage/Resources/Public/Icons/close.svg',
        ],
        // Site-scoped icon — only included in the sprite for site "brand-a"
        'icon-brand-logo' => [
            'src'   => 'EXT:brand_a/Resources/Public/Icons/logo.svg',
            'sites' => ['brand-a'],
        ],
    ];

The symbol array key is the ID used in ``<mai:svgSprite use="icon-arrow" />``.

The optional ``'sites'`` key restricts a symbol to specific TYPO3 site identifiers. Entries
without ``'sites'`` are global. See :ref:`configuration-multisite` for details.

Complete Layout Example
-----------------------

.. code-block:: html

    <!-- Layout.html — symbols registered in SpriteIcons.php, no register calls needed -->
    <html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers">
    <head>
        <!-- Critical CSS inlined in <head> -->
        <mai:scss identifier="critical" priority="true" inline="true">
            body { margin: 0; font-family: sans-serif; }
        </mai:scss>
    </head>
    <body>
        <header>
            <!-- Use any symbol registered in SpriteIcons.php -->
            <mai:svgSprite use="icon-logo" width="120" height="40" aria-label="Company Logo" />
        </header>

        <f:render section="Content" />

        <!-- Non-critical CSS loaded deferred -->
        <mai:scss src="EXT:theme/Resources/Private/Scss/layout.scss" />

        <!-- JS at end of body, deferred -->
        <mai:js src="EXT:theme/Resources/Public/JavaScript/app.js" />
    </body>
    </html>

.. _viewhelper-svginline:

mai:svgInline
=============

Embed an SVG file directly as inline ``<svg>`` markup in the HTML document.

Unlike ``<mai:svgSprite>`` (which outputs an ``<svg><use>`` sprite reference),
``<mai:svgInline>`` reads the source file and injects the full ``<svg>`` element.
Use this when the SVG needs to be styled via CSS (e.g. ``fill: currentColor``),
contains JS-driven animations, or must render without a separate network request.

The processed result is cached in the ``maispace_assets`` TYPO3 caching framework
cache — repeated uses in the same template incur no additional file reads or DOM parsing.

.. code-block:: html

    <!-- Decorative logo (aria-hidden="true" by default) -->
    <mai:svgInline src="EXT:theme/Resources/Public/Icons/logo.svg"
                   class="logo" width="120" height="40" />

    <!-- Meaningful SVG with accessible label -->
    <mai:svgInline src="EXT:theme/Resources/Public/Icons/checkmark.svg"
                   aria-label="Success" width="24" height="24" />

    <!-- SVG with title element for screen readers -->
    <mai:svgInline src="EXT:theme/Resources/Public/Icons/logo.svg"
                   title="Company Logo" aria-label="Company Logo" />

Generated Output
----------------

For a typical decorative SVG call:

.. code-block:: html

    <svg xmlns="http://www.w3.org/2000/svg" class="logo" width="120" height="40" aria-hidden="true">
      <!-- original SVG content -->
    </svg>

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 20 10 10 15 45

    * - Argument
      - Type
      - Required
      - Default
      - Description
    * - ``src``
      - string
      - **Yes**
      - —
      - EXT: path (e.g. ``EXT:my_ext/Resources/Public/Icons/logo.svg``) or absolute
        filesystem path to the SVG file. Only local filesystem paths are supported —
        external URLs are not fetched.
    * - ``class``
      - string
      - No
      - null
      - CSS class(es) to set on the root ``<svg>`` element. Replaces any existing
        ``class`` attribute in the source file.
    * - ``width``
      - string
      - No
      - null
      - Override the ``width`` attribute on the root ``<svg>`` element (e.g. ``"24"``
        or ``"1.5rem"``).
    * - ``height``
      - string
      - No
      - null
      - Override the ``height`` attribute on the root ``<svg>`` element.
    * - ``aria-hidden``
      - string
      - No
      - ``"true"``
      - Defaults to ``"true"`` for decorative SVGs. Set to ``"false"`` explicitly
        together with ``aria-label`` to expose the SVG to screen readers.
    * - ``aria-label``
      - string
      - No
      - null
      - Accessible label for the SVG. When set, ``role="img"`` is added automatically
        and ``aria-hidden`` is omitted.
    * - ``title``
      - string
      - No
      - null
      - Set or replace the ``<title>`` element as the first child of ``<svg>``.
        The title is read by screen readers when no ``aria-label`` is present on
        the element.
    * - ``additionalAttributes``
      - array
      - No
      - ``[]``
      - Additional HTML attributes merged onto the root ``<svg>`` element.

.. _viewhelper-hint:

mai:hint
========

Emit a resource hint ``<link>`` tag into ``<head>``.

Supports ``preconnect``, ``dns-prefetch``, ``modulepreload``, ``prefetch``, and ``preload``.
All hints are injected via ``PageRenderer::addHeaderData()`` and always land in ``<head>`` —
resource hints only work there.

.. code-block:: html

    <!-- Warm up a TCP+TLS connection to a CDN origin -->
    <mai:hint rel="preconnect" href="https://fonts.gstatic.com" crossorigin="anonymous" />

    <!-- Cheap DNS-only hint (no TLS handshake) -->
    <mai:hint rel="dns-prefetch" href="https://cdn.example.com" />

    <!-- Preload an ES module and its dependencies in parallel -->
    <mai:hint rel="modulepreload" href="/assets/app.js" />

    <!-- Preload a web font (crossorigin is required for fonts) -->
    <mai:hint rel="preload" href="/fonts/Inter.woff2"
             as="font" type="font/woff2" crossorigin="anonymous" />

    <!-- Conditional preload scoped to a viewport size -->
    <mai:hint rel="preload" href="/images/hero-mobile.webp"
             as="image" media="(max-width: 767px)" />

    <!-- Prefetch a resource likely needed on the next navigation -->
    <mai:hint rel="prefetch" href="/next-page.html" />

This ViewHelper produces no visible output — it only injects into ``<head>``.

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 20 10 10 15 45

    * - Argument
      - Type
      - Required
      - Default
      - Description
    * - ``href``
      - string
      - **Yes**
      - —
      - The URL of the resource to hint. For ``preconnect`` / ``dns-prefetch`` this is
        the origin (e.g. ``https://cdn.example.com``). For ``preload`` / ``modulepreload``
        it is the full asset URL.
    * - ``rel``
      - string
      - **Yes**
      - —
      - The link relationship type. Accepted values: ``preconnect``, ``dns-prefetch``,
        ``modulepreload``, ``prefetch``, ``preload``.
    * - ``as``
      - string
      - No
      - null
      - Destination for ``preload`` / ``modulepreload`` hints. Accepted values:
        ``script``, ``style``, ``font``, ``image``, ``document``, ``fetch``.
        Required for ``rel="preload"``; optional for ``modulepreload`` (defaults to
        ``"script"`` per spec).
    * - ``type``
      - string
      - No
      - null
      - MIME type of the resource, e.g. ``font/woff2`` or ``image/webp``. Helps
        the browser decide whether to honour the hint based on format support.
    * - ``crossorigin``
      - string
      - No
      - null
      - Add the ``crossorigin`` attribute. Required for font preloads (``"anonymous"``).
        Also needed on ``preconnect`` when the connection will be used for CORS requests.
        Accepted values: ``anonymous``, ``use-credentials``.
    * - ``media``
      - string
      - No
      - null
      - Media query scoping the hint, e.g. ``(max-width: 767px)``. The browser only
        acts on the hint when the media query matches.
    * - ``additionalAttributes``
      - array
      - No
      - ``[]``
      - Additional HTML attributes merged onto the ``<link>`` tag.

.. _viewhelper-lottie:

mai:lottie
==========

Render a Lottie animation using the ``<lottie-player>`` web component.

Lottie is a JSON-based animation format that renders vector animations exported from
Adobe After Effects. This ViewHelper outputs a ``<lottie-player>`` custom element and
optionally registers the player JavaScript via TYPO3's AssetCollector.

The player script (``@lottiefiles/lottie-player``) is loaded as a ``type="module"``
script so it never blocks rendering. Configure the default player URL via TypoScript:

.. code-block:: typoscript

    plugin.tx_maispace_assets.lottie.playerSrc = https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js

.. code-block:: html

    <!-- Basic looping animation from an EXT: path -->
    <mai:lottie src="EXT:theme/Resources/Public/Animations/hero.json"
                width="400px" height="400px" />

    <!-- One-shot animation without controls, no loop -->
    <mai:lottie src="EXT:theme/Resources/Public/Animations/checkmark.json"
                loop="false" autoplay="true" width="80px" height="80px" />

    <!-- Bouncing animation with visible player controls -->
    <mai:lottie src="/animations/wave.json"
                mode="bounce" controls="true" width="300px" />

    <!-- Reverse playback direction -->
    <mai:lottie src="/animations/loading.json"
                direction="-1" loop="true" />

    <!-- External Lottie JSON from a CDN -->
    <mai:lottie src="https://assets.example.com/animations/hero.json"
                width="100%" height="500px" />

    <!-- User manages player script themselves — skip auto-registration -->
    <mai:lottie src="/animations/icon.json" playerSrc="" width="48px" />

Generated Output
----------------

For ``<mai:lottie src="..." autoplay="true" loop="true" width="400px" height="400px" />``:

.. code-block:: html

    <lottie-player src="/animations/hero.json" autoplay="autoplay" loop="loop"
                   style="width:400px;height:400px;"></lottie-player>

The player script is registered once in the AssetCollector regardless of how many
``<mai:lottie>`` tags appear on the page (same identifier prevents duplication).

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 22 10 10 15 43

    * - Argument
      - Type
      - Required
      - Default
      - Description
    * - ``src``
      - string
      - **Yes**
      - —
      - Path to the Lottie JSON animation file. Accepts ``EXT:`` notation, a
        public-relative path, or an external URL (passed through unchanged).
    * - ``autoplay``
      - bool
      - No
      - ``true``
      - Start the animation automatically when it enters the viewport.
    * - ``loop``
      - bool
      - No
      - ``true``
      - Loop the animation continuously.
    * - ``controls``
      - bool
      - No
      - ``false``
      - Show built-in player controls (play/pause/progress).
    * - ``speed``
      - float
      - No
      - ``1.0``
      - Playback speed multiplier. ``1.0`` = normal, ``0.5`` = half, ``2.0`` = double.
    * - ``direction``
      - int
      - No
      - ``1``
      - Playback direction. ``1`` = forward, ``-1`` = backward.
    * - ``mode``
      - string
      - No
      - ``"normal"``
      - Playback mode. ``"normal"`` = play through (or loop). ``"bounce"`` = play
        forward then reverse.
    * - ``renderer``
      - string
      - No
      - ``"svg"``
      - Rendering engine. ``"svg"`` (best quality/scaling), ``"canvas"`` (better
        performance), ``"html"`` (CSS-based).
    * - ``background``
      - string
      - No
      - ``"transparent"``
      - Background colour of the animation container. Accepts any CSS colour value.
    * - ``width``
      - string
      - No
      - null
      - Width of the animation container (e.g. ``"400px"`` or ``"100%"``). Applied
        as an inline ``style`` attribute on the ``<lottie-player>`` element.
    * - ``height``
      - string
      - No
      - null
      - Height of the animation container. Applied as an inline ``style`` attribute.
    * - ``class``
      - string
      - No
      - null
      - CSS class(es) for the ``<lottie-player>`` element.
    * - ``playerSrc``
      - string
      - No
      - null
      - URL or EXT: path to the lottie-player JavaScript library. When ``null``
        (default), falls back to ``plugin.tx_maispace_assets.lottie.playerSrc``
        from TypoScript. Pass an empty string ``""`` to skip auto-registration
        entirely (useful when you include the player via another mechanism).
    * - ``playerIdentifier``
      - string
      - No
      - ``"maispace-lottie-player"``
      - AssetCollector identifier for the player script. Override when including
        multiple Lottie player versions on the same page.
    * - ``additionalAttributes``
      - array
      - No
      - ``[]``
      - Additional HTML attributes merged onto the ``<lottie-player>`` element.

.. _viewhelper-image:

mai:image
=========

Render a single responsive ``<img>`` tag. Images are processed via TYPO3's native
``ImageService``, which handles resizing, cropping, and format conversion (including WebP
when configured in Install Tool).

**Accepted image types:** ``sys_file_reference`` UID (int), FAL ``File`` / ``FileReference``
object, ``EXT:`` path, or public-relative path string.

**Width/height notation:** ``800`` (exact) · ``800c`` (centre crop) · ``800m`` (max, proportional).

.. code-block:: html

    <!-- From a sys_file_reference UID -->
    <mai:image image="{file.uid}" alt="{file.alternative}" width="800" />

    <!-- Hero: preloaded, high fetchpriority, no lazy -->
    <mai:image image="{hero}" alt="{heroAlt}" width="1920"
              lazyloading="false" preload="true" fetchPriority="high" />

    <!-- Hero preload scoped to desktop viewports (avoids loading on mobile) -->
    <mai:image image="{heroDesktop}" alt="{alt}" width="1920"
              preload="true" preloadMedia="(min-width: 768px)" lazyloading="false" />

    <!-- Lazy load with a JS-hook class for lazysizes -->
    <mai:image image="{img}" alt="{alt}" width="427c" height="240"
              lazyloadWithClass="lazyload" />

    <!-- From an EXT: path (static asset) -->
    <mai:image image="EXT:theme/Resources/Public/Images/logo.png" alt="Logo" width="200m" />

    <!-- Explicit JPEG quality -->
    <mai:image image="{img}" alt="{alt}" width="800" quality="75" />

    <!-- Async decode (non-blocking, ideal for below-the-fold images) -->
    <mai:image image="{img}" alt="{alt}" width="800" decoding="async" />

    <!-- CORS-enabled image (needed for canvas/WebGL pixel access) -->
    <mai:image image="{img}" alt="{alt}" width="800" crossorigin="anonymous" />

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 22 10 10 15 43

    * - Argument
      - Type
      - Required
      - Default
      - Description
    * - ``image``
      - mixed
      - **Yes**
      - —
      - UID (int), File/FileReference object, ``EXT:`` path, or public-relative string path.
    * - ``alt``
      - string
      - **Yes**
      - —
      - Alt text. Pass an empty string for decorative images.
    * - ``width``
      - string
      - No
      - ``''``
      - Width in TYPO3 notation: ``800``, ``800c``, ``800m``.
    * - ``height``
      - string
      - No
      - ``''``
      - Height in TYPO3 notation. Derived proportionally from width when empty.
    * - ``quality``
      - int
      - No
      - ``0``
      - Output quality for lossy formats (JPEG, WebP, AVIF). Range: 1–100.
        ``0`` uses the ImageMagick/GraphicsMagick default (usually 75–85).
        Has no effect on lossless formats (PNG, GIF).
    * - ``lazyloading``
      - bool
      - No
      - null
      - Add ``loading="lazy"``. ``null`` inherits ``image.lazyloading`` from TypoScript.
    * - ``lazyloadWithClass``
      - string
      - No
      - null
      - CSS class added alongside ``loading="lazy"``. Also enables lazy loading.
        ``null`` inherits ``image.lazyloadWithClass`` from TypoScript.
    * - ``fetchPriority``
      - string
      - No
      - null
      - ``fetchpriority`` attribute. Allowed values: ``high``, ``low``, ``auto``.
    * - ``preload``
      - bool
      - No
      - ``false``
      - Add ``<link rel="preload" as="image">`` to ``<head>``.
    * - ``preloadMedia``
      - string
      - No
      - null
      - Media query to scope the preload hint, e.g. ``"(min-width: 768px)"``.
        Only used when ``preload="true"``. Prevents the browser from preloading
        an image that is irrelevant at the current viewport size.
    * - ``class``
      - string
      - No
      - null
      - CSS class(es) for the ``<img>`` element.
    * - ``id``
      - string
      - No
      - null
      - ``id`` attribute for the ``<img>`` element.
    * - ``title``
      - string
      - No
      - null
      - ``title`` attribute for the ``<img>`` element.
    * - ``additionalAttributes``
      - array
      - No
      - ``[]``
      - Additional HTML attributes merged onto the ``<img>`` tag.
    * - ``srcset``
      - string
      - No
      - null
      - Comma-separated list of target widths for the ``srcset`` attribute,
        e.g. ``"400, 800, 1200"``. Each width is processed independently; the actual
        output pixel width becomes the ``w`` descriptor. Accepts TYPO3 width notation
        (``"400c"``, ``"800m"``). The main ``src`` uses the ``width`` argument as usual.
        Use :ref:`viewhelper-picture` for breakpoint-based art direction.
    * - ``sizes``
      - string
      - No
      - null
      - Value for the HTML ``sizes`` attribute, e.g. ``"(max-width: 768px) 100vw, 50vw"``.
        Has no effect when ``srcset`` is not set.
    * - ``fileExtension``
      - string
      - No
      - null
      - Force the output image format, e.g. ``"webp"`` or ``"avif"``. Applied to both
        ``src`` and all ``srcset`` entries. Overrides ``image.forceFormat`` from TypoScript.
        Leave empty to use the source file format or the global TypoScript default.
    * - ``decoding``
      - string
      - No
      - null
      - ``decoding`` attribute on the ``<img>`` tag. Controls whether the browser decodes the image
        synchronously or in parallel. Values: ``async`` (non-blocking, recommended for
        below-the-fold images), ``sync`` (blocking), ``auto`` (browser decides).
    * - ``crossorigin``
      - string
      - No
      - null
      - ``crossorigin`` attribute on the ``<img>`` tag. Required when pixel data from a
        cross-origin image is needed (canvas, WebGL). Values: ``anonymous``, ``use-credentials``.

Responsive images with srcset
------------------------------

For simple responsive use cases that don't need different images per breakpoint, add
``srcset`` and ``sizes`` to a single ``<mai:image>`` instead of reaching for ``<mai:picture>``:

.. code-block:: html

    <!-- Single image, multiple sizes for the browser to pick from -->
    <mai:image image="{imageRef}" alt="{alt}" width="1200"
               srcset="400, 800, 1200, 1600"
               sizes="(max-width: 600px) 100vw, (max-width: 1200px) 80vw, 1200px" />

    <!-- Output:
    <img src="/img_1200.jpg" width="1200" height="675"
         srcset="/img_400.jpg 400w, /img_800.jpg 800w, /img_1200.jpg 1200w, /img_1600.jpg 1600w"
         sizes="(max-width: 600px) 100vw, (max-width: 1200px) 80vw, 1200px"
         loading="lazy" alt="..."> -->

    <!-- Responsive WebP with srcset -->
    <mai:image image="{imageRef}" alt="{alt}" width="1200"
               srcset="400, 800, 1200" fileExtension="webp"
               sizes="(max-width: 768px) 100vw, 60vw" />

.. _viewhelper-picture:

mai:picture
===========

Render a responsive ``<picture>`` element. Child ``<mai:picture.source>`` ViewHelpers define
the ``<source>`` tags. A fallback ``<img>`` is appended automatically from the parent image
and dimensions.

.. code-block:: html

    <mai:picture image="{imageRef}" alt="{alt}" width="1200" lazyloadWithClass="lazyload">
        <mai:picture.source media="(min-width: 980px)" width="1200" height="675" />
        <mai:picture.source media="(min-width: 768px)" width="800" height="450" />
        <mai:picture.source media="(max-width: 767px)" width="400" height="225" />
    </mai:picture>

    <!-- Hero picture: no lazy, preloaded fallback scoped to desktop, high fetchpriority -->
    <mai:picture image="{hero}" alt="{heroAlt}" width="1920"
                lazyloading="false" preload="true" preloadMedia="(min-width: 768px)"
                fetchPriority="high">
        <mai:picture.source media="(min-width: 768px)" width="1920" />
        <mai:picture.source media="(max-width: 767px)" width="600" />
    </mai:picture>

    <!-- CSS class on the <picture> independent of the fallback <img> -->
    <mai:picture image="{img}" alt="{alt}" width="1200"
                class="picture-wrapper" imgClass="content-image" imgId="hero-img">
        <mai:picture.source media="(min-width: 768px)" width="1200" />
    </mai:picture>

    <!-- AVIF + WebP source sets with explicit quality -->
    <mai:picture image="{imageRef}" alt="{alt}" width="1200" quality="80" formats="avif, webp">
        <mai:picture.source media="(min-width: 768px)" width="1200" />
        <mai:picture.source media="(max-width: 767px)" width="400" />
    </mai:picture>

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 22 10 10 15 43

    * - Argument
      - Type
      - Required
      - Default
      - Description
    * - ``image``
      - mixed
      - **Yes**
      - —
      - Same as ``mai:image``.
    * - ``alt``
      - string
      - **Yes**
      - —
      - Alt text for the fallback ``<img>``.
    * - ``width``
      - string
      - No
      - ``''``
      - Width for the fallback ``<img>`` in TYPO3 notation.
    * - ``height``
      - string
      - No
      - ``''``
      - Height for the fallback ``<img>``.
    * - ``quality``
      - int
      - No
      - ``0``
      - Output quality for lossy formats (JPEG, WebP, AVIF). Range: 1–100.
        ``0`` uses the ImageMagick/GraphicsMagick default. Applied to all processed
        images: fallback ``<img>``, format ``<source>`` tags, and child
        ``<mai:picture.source>`` elements.
    * - ``lazyloading``
      - bool
      - No
      - null
      - Propagated to the fallback ``<img>``. ``null`` inherits TypoScript default.
    * - ``lazyloadWithClass``
      - string
      - No
      - null
      - CSS class added to the fallback ``<img>`` alongside ``loading="lazy"``.
    * - ``fetchPriority``
      - string
      - No
      - null
      - ``fetchpriority`` on the fallback ``<img>``. Values: ``high``, ``low``, ``auto``.
    * - ``preload``
      - bool
      - No
      - ``false``
      - Emit ``<link rel="preload" as="image">`` for the fallback image URL.
    * - ``preloadMedia``
      - string
      - No
      - null
      - Media query to scope the preload hint, e.g. ``"(min-width: 768px)"``.
        Only used when ``preload="true"``.
    * - ``class``
      - string
      - No
      - null
      - CSS class(es) for the ``<picture>`` element.
    * - ``additionalAttributes``
      - array
      - No
      - ``[]``
      - Additional HTML attributes on the ``<picture>`` tag.
        To set attributes on the fallback ``<img>``, use ``imgAdditionalAttributes``.
    * - ``imgClass``
      - string
      - No
      - null
      - CSS class(es) for the fallback ``<img>`` element inside ``<picture>``.
    * - ``imgId``
      - string
      - No
      - null
      - ``id`` attribute for the fallback ``<img>`` element.
    * - ``imgTitle``
      - string
      - No
      - null
      - ``title`` attribute for the fallback ``<img>`` element.
    * - ``imgAdditionalAttributes``
      - array
      - No
      - ``[]``
      - Additional HTML attributes merged onto the fallback ``<img>`` tag.
    * - ``formats``
      - string
      - No
      - null
      - Comma-separated list of target formats in preference order, e.g. ``"avif, webp"``.
        Renders one ``<source type="image/...">`` per format before the fallback ``<img>``,
        allowing browsers to pick the best supported format. Falls back to the TypoScript
        setting ``image.alternativeFormats`` when not set.
    * - ``fallback``
      - bool
      - No
      - ``true``
      - When ``formats`` is set, also emit a ``<source>`` for the original (unmodified)
        format directly before the fallback ``<img>``. Set to ``false`` to omit it.
    * - ``fileExtension``
      - string
      - No
      - null
      - Force the output format for the fallback ``<img>`` only (e.g. ``"webp"``).
        Overrides ``image.forceFormat`` from TypoScript. Has no effect on ``<source>``
        tags generated by the ``formats`` argument.
    * - ``imgDecoding``
      - string
      - No
      - null
      - ``decoding`` attribute on the fallback ``<img>`` tag. Values: ``async``, ``sync``, ``auto``.
    * - ``imgCrossorigin``
      - string
      - No
      - null
      - ``crossorigin`` attribute on the fallback ``<img>`` tag. Values: ``anonymous``, ``use-credentials``.

.. _viewhelper-picture-source:

mai:picture.source
==================

Render a single ``<source>`` tag inside a ``<mai:picture>`` element. Must be a direct child
of ``<mai:picture>``. Inherits the parent image unless overridden via the ``image`` argument.

.. code-block:: html

    <mai:picture image="{desktopImg}" alt="{alt}" width="1200">
        <!-- Inherits parent image, processed to 1200px -->
        <mai:picture.source media="(min-width: 768px)" width="1200" />
        <!-- Override image for small screens -->
        <mai:picture.source image="{mobileImg}" media="(max-width: 767px)" width="400" />
    </mai:picture>

    <!-- Responsive srcset on a <source> tag (multiple widths per breakpoint) -->
    <mai:picture image="{imageRef}" alt="{alt}" width="1200">
        <mai:picture.source media="(min-width: 768px)"
                            srcset="800, 1200, 1600"
                            sizes="(min-width: 1200px) 1200px, 100vw" />
        <mai:picture.source media="(max-width: 767px)"
                            srcset="400, 600, 800"
                            sizes="100vw" />
    </mai:picture>

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 22 10 10 15 43

    * - Argument
      - Type
      - Required
      - Default
      - Description
    * - ``media``
      - string
      - No
      - null
      - Media query, e.g. ``(min-width: 768px)``. Omit for a catch-all source.
    * - ``width``
      - string
      - No
      - ``''``
      - Target width in TYPO3 notation.
    * - ``height``
      - string
      - No
      - ``''``
      - Target height in TYPO3 notation.
    * - ``image``
      - mixed
      - No
      - null
      - Override image for this breakpoint. Inherits parent ``<mai:picture>`` image when absent.
    * - ``quality``
      - int
      - No
      - ``0``
      - Output quality for lossy formats. ``0`` inherits the parent ``<mai:picture>``
        ``quality`` argument, or uses the ImageMagick/GM default if neither is set.
    * - ``type``
      - string
      - No
      - null
      - MIME type for the ``<source>`` tag (e.g. ``image/webp``). Auto-detected from the
        processed file extension when omitted. Has no effect when ``formats`` is set.
    * - ``formats``
      - string
      - No
      - null
      - Comma-separated list of target formats in preference order, e.g. ``"avif, webp"``.
        Renders one ``<source>`` per format before the original-format source. Falls back to
        the TypoScript setting ``image.alternativeFormats`` when not set.
    * - ``fallback``
      - bool
      - No
      - ``true``
      - When ``formats`` is set, also emit a ``<source>`` for the original (unmodified)
        format as a final fallback within the ``<picture>``. Set to ``false`` to omit it.
    * - ``fileExtension``
      - string
      - No
      - null
      - Force the output format when ``formats`` is not set, e.g. ``"webp"``.
        Overrides ``image.forceFormat`` from TypoScript.
    * - ``srcset``
      - string
      - No
      - null
      - Comma-separated list of widths to generate for the ``srcset`` attribute on the
        ``<source>`` tag, e.g. ``"400, 800, 1200"``. Each width produces one ``url Nw``
        descriptor; the actual rendered pixel width is used as the descriptor value.
        The ``width`` argument still controls the single-URL fallback within the tag.
    * - ``sizes``
      - string
      - No
      - null
      - Value for the ``sizes`` attribute on the ``<source>`` tag,
        e.g. ``"(min-width: 768px) 1200px, 100vw"``. Only rendered when ``srcset`` is also set.

.. _viewhelper-figure:

mai:figure
==========

Wrap content in a semantic ``<figure>`` element with an optional ``<figcaption>``. Intended
as a standalone wrapper for images or any content that benefits from the figure/caption
structure. Kept separate from ``mai:picture`` and ``mai:image`` so each ViewHelper has a
single, focused responsibility.

.. code-block:: html

    <!-- Minimal wrapper, no caption -->
    <mai:figure>
        <mai:picture image="{file}" alt="{alt}" width="800" />
    </mai:figure>

    <!-- With caption text -->
    <mai:figure caption="{file.description}" class="article-figure" classFigcaption="caption">
        <mai:image image="{file.uid}" alt="{file.alternative}" width="600" />
    </mai:figure>

The ``caption`` argument is HTML-escaped. For a caption containing markup, omit the argument
and place a ``<figcaption>`` element directly inside the ViewHelper's child content.

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 22 10 10 15 43

    * - Argument
      - Type
      - Required
      - Default
      - Description
    * - ``caption``
      - string
      - No
      - null
      - Caption text rendered inside ``<figcaption>``. HTML-escaped.
    * - ``class``
      - string
      - No
      - null
      - CSS class(es) for the ``<figure>`` element.
    * - ``classFigcaption``
      - string
      - No
      - null
      - CSS class(es) for the ``<figcaption>`` element.

Font Preloading
===============

Fonts are not registered via a ViewHelper. Instead, drop a ``Configuration/Fonts.php``
file into any extension and return an array of font definitions. The font registry
auto-discovers this file across all loaded extensions and emits
``<link rel="preload" as="font" crossorigin>`` tags in ``<head>`` via
``BeforeStylesheetsRenderingEvent``.

.. code-block:: php

    <?php
    // EXT:my_sitepackage/Configuration/Fonts.php
    declare(strict_types=1);

    return [
        'my-font-regular' => [
            'src'  => 'EXT:my_sitepackage/Resources/Public/Fonts/MyFont-Regular.woff2',
            // 'type' is auto-detected: .woff2 → font/woff2
        ],
        'my-font-bold' => [
            'src'     => 'EXT:my_sitepackage/Resources/Public/Fonts/MyFont-Bold.woff2',
            'preload' => false,  // loaded normally, no preload tag
        ],
        // Site-scoped font — only preloaded on "brand-a"
        'brand-a-display' => [
            'src'   => 'EXT:brand_a/Resources/Public/Fonts/Display.woff2',
            'sites' => ['brand-a'],
        ],
    ];

Supported font types (auto-detected from extension): ``.woff2``, ``.woff``, ``.ttf``, ``.otf``.
