.. include:: /Includes.rst.txt

.. _introduction:

============
Introduction
============

**Maispace Assets** provides Fluid ViewHelpers for CSS, JavaScript, SCSS, images, SVG
sprites, and web font preloading — all from Fluid templates, with performance-first defaults.
Assets are minified, cached, and delivered in a non-render-blocking way out of the box.

Features
========

CSS & JS ViewHelpers
--------------------

*  Include assets **inline** (written in the Fluid template body) or from a **file path**
   using the ``EXT:`` notation.
*  **Minification** via `matthiasmullie/minify <https://github.com/matthiasmullie/minify>`__
   (disabled automatically in debug mode).
*  **Deferred CSS loading** using the ``media="print"`` onload-swap technique — non-blocking
   without JavaScript dependencies. A ``<noscript>`` fallback is added automatically.
*  **Deferred JS** via the native ``defer`` or ``async`` HTML attributes.
*  **Inline in ``<head>``** for critical above-the-fold CSS or JS.
*  **File caching**: processed assets are written to ``typo3temp/assets/maispace_assets/``
   and cached by the TYPO3 caching framework (flushed with the page cache).

SCSS ViewHelper
---------------

*  Compile SCSS to CSS **server-side** using `scssphp <https://scssphp.github.io/scssphp/>`__
   — no Node.js, no build pipeline required.
*  Supports ``@import`` / ``@use`` with configurable import paths (``EXT:`` notation).
*  Cache is automatically **invalidated when the source file changes** (via ``filemtime``),
   so you never need to manually flush the cache during development.
*  The ``minify`` option uses scssphp's ``OutputStyle::COMPRESSED`` — no redundant
   double-pass through a CSS minifier.

Image ViewHelpers
-----------------

*  ``<mai:image>`` renders a single ``<img>`` tag; ``<mai:picture>`` with ``<mai:picture.source>``
   children renders a responsive ``<picture>`` element.
*  Source sets are configured **inline in the template** — no central YAML or TypoScript
   configuration file needed per image.
*  Images are processed via TYPO3's native **ImageService**, which handles resizing, cropping,
   and format conversion (including WebP when configured in Install Tool).
*  Accepts **FAL UIDs**, ``File`` / ``FileReference`` objects, or ``EXT:`` string paths.
*  Width/height strings use TYPO3 notation: ``800`` (exact), ``800c`` (crop), ``800m`` (max).
*  Native **lazy loading** via ``loading="lazy"`` (configurable globally and per image).
*  Optional CSS class alongside lazy loading for JS-based loaders (e.g. lazysizes).
*  ``fetchpriority`` attribute support for hero images.
*  ``<link rel="preload" as="image">`` emission via the ``preload`` argument.
*  ``<mai:figure>`` provides a standalone semantic ``<figure>`` / ``<figcaption>`` wrapper.

SVG Sprite ViewHelper
---------------------

*  Icons are registered declaratively in ``Configuration/SpriteIcons.php`` — no template
   register/render calls needed.
*  The sprite is assembled from all ``SpriteIcons.php`` files found across loaded extensions,
   cached, and served from a dedicated HTTP endpoint (default: ``/maispace/sprite.svg``).
*  The endpoint is browser-cacheable for one year (``Cache-Control: public, max-age=31536000,
   immutable``) and supports conditional GET via ETag for efficient revalidation.
*  Reference symbols anywhere with ``<mai:svgSprite use="icon-name" />``.
*  Fully accessible: decorative icons default to ``aria-hidden="true"``; pass ``aria-label``
   to make an icon meaningful to screen readers.

Font Preloading
---------------

*  Register web fonts in ``Configuration/Fonts.php`` in any extension.
*  The registry auto-discovers these files and emits ``<link rel="preload" as="font"
   crossorigin>`` tags in ``<head>`` automatically.
*  Font files are served from their stable public URLs — no temp file generation.
*  Per-font preloading can be disabled with ``'preload' => false``.
*  A global TypoScript kill-switch (``fonts.preload = 0``) suppresses all output.

Multi-site Scoping
------------------

*  Both ``SpriteIcons.php`` and ``Fonts.php`` support an optional ``'sites'`` key —
   an array of TYPO3 site identifiers.
*  Entries with ``'sites'`` are only included when the current request's site matches.
*  Entries without ``'sites'`` are global (available on all sites).
*  Each site gets its own independently cached sprite so only relevant icons are served.

Extensibility
-------------

*  **PSR-14 events** are dispatched after each asset is processed, giving listeners the
   opportunity to modify the output (e.g., inject CSS custom properties from a database,
   add copyright headers, or log processing metrics).
*  **Five example event listeners** ship with the extension and document every available
   event API method. They are inactive by default — activate them in your site package.
*  All default behaviours (minify, defer, lazy loading, cache) can be overridden globally via
   **TypoScript** or per-asset via **ViewHelper arguments**.

Global ViewHelper Namespace
===========================

The namespace ``ma`` is registered globally, so no ``{namespace}`` declaration is needed
at the top of your Fluid templates:

.. code-block:: html

    <!-- CSS from a file (deferred by default) -->
    <mai:css src="EXT:theme/Resources/Public/Css/app.css" />

    <!-- Inline JS -->
    <mai:js identifier="page-init">
        document.addEventListener('DOMContentLoaded', function() { console.log('ready'); });
    </mai:js>

    <!-- SCSS compiled server-side -->
    <mai:scss src="EXT:theme/Resources/Private/Scss/main.scss" />

    <!-- Single responsive image with lazy loading -->
    <mai:image image="{file.uid}" alt="{file.alternative}" width="800" />

    <!-- Responsive picture with breakpoints configured in the template -->
    <mai:picture image="{imageRef}" alt="{alt}" width="1200">
        <mai:picture.source media="(min-width: 768px)" width="1200" />
        <mai:picture.source media="(max-width: 767px)" width="400" />
    </mai:picture>

    <!-- SVG icon from the auto-discovered sprite -->
    <mai:svgSprite use="icon-arrow" width="24" height="24" class="icon" />
