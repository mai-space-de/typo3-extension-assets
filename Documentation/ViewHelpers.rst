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
      - EXT: path (e.g. ``EXT:my_ext/Resources/Public/Css/app.css``) or absolute
        path to a CSS file. When provided, inline child node content is ignored.
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
        ``null`` inherits the global setting.
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
    * - ``media``
      - string
      - No
      - ``all``
      - The ``media`` attribute for the generated ``<link>`` tag.
    * - ``nonce``
      - string
      - No
      - null
      - CSP nonce value added as a ``nonce`` attribute on inline ``<style>`` tags.
        Only applied when ``inline="true"``. Generate a per-request cryptographic nonce
        in a PSR-15 middleware and pass it here to satisfy a ``Content-Security-Policy``
        that restricts inline styles.
    * - ``integrity``
      - bool
      - No
      - null
      - When ``true``, automatically compute a SHA-384 SRI hash of the processed CSS
        and add an ``integrity`` attribute to the generated ``<link>`` tag.
        Only applied for external file assets (not inline). Browsers refuse to load
        the file if the hash does not match.
    * - ``crossorigin``
      - string
      - No
      - null
      - Value for the ``crossorigin`` attribute added alongside ``integrity``.
        Defaults to ``"anonymous"`` when ``integrity`` is enabled.

.. _viewhelper-js:

mai:js
======

Include a JavaScript asset from a file or inline Fluid content.

.. code-block:: html

    <!-- External file (deferred by default per TypoScript) -->
    <mai:js src="EXT:theme/Resources/Public/JavaScript/app.js" />

    <!-- ES module -->
    <mai:js src="EXT:theme/Resources/Public/JavaScript/app.js" type="module" />

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
      - EXT: path or absolute path to a JS file.
    * - ``priority``
      - bool
      - No
      - ``false``
      - Place in ``<head>`` when ``true``.
    * - ``minify``
      - bool
      - No
      - null
      - Override the TypoScript ``js.minify`` setting.
    * - ``defer``
      - bool
      - No
      - null
      - Add the ``defer`` attribute. ``null`` inherits ``js.defer`` from TypoScript (default: 1).
    * - ``async``
      - bool
      - No
      - ``false``
      - Add the ``async`` attribute. The script is fetched in parallel and executed
        as soon as available.
    * - ``type``
      - string
      - No
      - null
      - The ``type`` attribute (e.g. ``"module"`` for ES modules, ``"importmap"`` for
        inline import maps). ES modules imply ``defer`` by the browser.
    * - ``nonce``
      - string
      - No
      - null
      - CSP nonce value added as a ``nonce`` attribute on inline ``<script>`` tags.
        Only applied for inline JS (no ``src`` set). Generate a per-request nonce in a
        PSR-15 middleware and pass it here to satisfy a ``Content-Security-Policy``.
    * - ``integrity``
      - bool
      - No
      - null
      - When ``true``, automatically compute a SHA-384 SRI hash of the processed JS
        and add an ``integrity`` attribute to the ``<script>`` tag.
        Only applied for external file assets. Browsers refuse to execute the script
        if the hash does not match.
    * - ``crossorigin``
      - string
      - No
      - null
      - Value for the ``crossorigin`` attribute added alongside ``integrity``.
        Defaults to ``"anonymous"`` when ``integrity`` is enabled.

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

    <!-- Inline SCSS (identifier derived from content hash) -->
    <mai:scss identifier="hero-theme">
        $primary: #e63946;
        $spacing: 1.5rem;
        .hero { background: $primary; padding: $spacing; color: white; }
    </mai:scss>

    <!-- Inline SCSS as <style> in <head> (critical styles) -->
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

    <!-- Lazy load with a JS-hook class for lazysizes -->
    <mai:image image="{img}" alt="{alt}" width="427c" height="240"
              lazyloadWithClass="lazyload" />

    <!-- From an EXT: path (static asset) -->
    <mai:image image="EXT:theme/Resources/Public/Images/logo.png" alt="Logo" width="200m" />

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

    <!-- Hero picture: no lazy, preloaded fallback, high fetchpriority -->
    <mai:picture image="{hero}" alt="{heroAlt}" width="1920"
                lazyloading="false" preload="true" fetchPriority="high">
        <mai:picture.source media="(min-width: 768px)" width="1920" />
        <mai:picture.source media="(max-width: 767px)" width="600" />
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
