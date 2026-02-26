.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

1.0.0 (2026-02-26)
==================

Initial release.

New Features
------------

Asset ViewHelpers
~~~~~~~~~~~~~~~~~

*  **CSS ViewHelper** (``ma:css``) — Include CSS from file (EXT: path) or inline Fluid
   content. Supports minification, deferred loading (``media="print"`` swap), inline
   ``<style>`` in ``<head>``, and the ``media`` attribute.

*  **JS ViewHelper** (``ma:js``) — Include JavaScript from file or inline. Supports
   minification, ``defer``, ``async``, ``type="module"``, and footer/head placement.

*  **SCSS ViewHelper** (``ma:scss``) — Compile SCSS to CSS server-side via scssphp
   (pure PHP, no Node.js required). Supports configurable import paths, cache
   auto-invalidation on file change (``filemtime``), and all CSS placement options.

Image ViewHelpers
~~~~~~~~~~~~~~~~~

*  **Image ViewHelper** (``ma:image``) — Render a single ``<img>`` tag from a
   sys_file_reference UID, FAL File/FileReference object, or EXT:/relative path string.
   Uses TYPO3's native ``ImageService`` for processing (resize, crop, WebP conversion).
   Supports lazy loading (``loading="lazy"``), JS-hook lazy-load CSS class, ``fetchpriority``
   attribute, and ``<link rel="preload" as="image">`` injection into ``<head>``.

*  **Picture ViewHelper** (``ma:picture``) — Render a responsive ``<picture>`` element.
   ``<ma:picture.source>`` children define each ``<source>`` tag inline in the template.
   Falls back to a ``<img>`` element using the same image and configurable fallback dimensions.
   Lazy loading, preload, and fetchpriority are propagated to the fallback ``<img>`` and to
   child ``<ma:picture.source>`` elements via the ViewHelper variable container.

*  **Picture Source ViewHelper** (``ma:picture.source``) — Define a single ``<source>`` tag
   inside ``<ma:picture>``. Accepts a ``media`` query, processing dimensions, optional image
   override per breakpoint, and optional ``type`` MIME hint.

*  **Figure ViewHelper** (``ma:figure``) — Wrap any content in a ``<figure>`` with an
   optional ``<figcaption>``. Kept deliberately separate from ``ma:picture`` and ``ma:image``
   for single-responsibility template composition.

SVG Sprite ViewHelper
~~~~~~~~~~~~~~~~~~~~~

*  **SVG Sprite ViewHelper** (``ma:svgSprite``) — Render an ``<svg><use href="...#id">``
   reference to a symbol served by the built-in ``SvgSpriteMiddleware``. Symbols are
   registered declaratively via ``Configuration/SpriteIcons.php`` in any loaded extension
   (no ``ext_localconf.php`` boilerplate). The middleware serves the assembled sprite at a
   configurable URL path (default ``/maispace/sprite.svg``) with full HTTP caching headers
   (``Cache-Control: immutable``, ETag, 304 support).

Registries
~~~~~~~~~~

*  **SVG Sprite Registry** — Auto-discovers ``Configuration/SpriteIcons.php`` from all
   loaded extensions. Symbol IDs must be unique; later-loaded extensions take precedence.
   Optional ``'sites'`` key restricts a symbol to specific TYPO3 site identifiers.
   Each site gets its own independently cached sprite.

*  **Font Preload Registry** — Auto-discovers ``Configuration/Fonts.php`` from all loaded
   extensions. Emits ``<link rel="preload" as="font" crossorigin>`` tags in ``<head>`` via
   ``BeforeStylesheetsRenderingEvent``. Per-font ``preload`` flag; global TypoScript
   kill-switch ``plugin.tx_maispace_assets.fonts.preload = 0``. Optional ``'sites'`` key
   restricts a font preload to specific sites.

Multi-site Scoping
~~~~~~~~~~~~~~~~~~

*  Both ``SpriteIcons.php`` and ``Fonts.php`` accept an optional ``'sites'`` array. Entries
   without ``'sites'`` are served on every site (backwards compatible). Entries with
   ``'sites'`` are filtered to matching TYPO3 site identifiers — avoiding cross-site
   preload pollution in multi-tenant TYPO3 instances.

PSR-14 Events
~~~~~~~~~~~~~

*  **PSR-14 Events** — Events dispatched at each processing stage:

   *  ``AfterCssProcessedEvent``
   *  ``AfterJsProcessedEvent``
   *  ``AfterScssCompiledEvent``
   *  ``AfterSpriteBuiltEvent``
   *  ``BeforeSpriteSymbolRegisteredEvent``

   All events expose mutable setters so listeners can modify the final output.
   ``BeforeSpriteSymbolRegisteredEvent`` allows filtering, renaming, or vetoing individual
   symbols before they are stored in the registry.

*  **Example Event Listeners** — Four documented example listeners (commented out by
   default) demonstrating CSS variable injection, JS configuration injection, SCSS
   size auditing, and SVG sprite symbol appending.

Other
~~~~~

*  **TYPO3 Caching Framework integration** — Processed assets are cached in the
   ``maispace_assets`` FileBackend cache (grouped with the page cache). SCSS file
   caches are additionally keyed by ``filemtime`` for automatic development-time
   invalidation. SVG sprite caches are keyed per-site and by the SHA-1 of symbol IDs,
   source paths, and file modification times.

*  **Debug mode** — All minification and deferral disabled automatically when a backend
   user is logged in and ``?debug=1`` is present in the URL.

*  **TypoScript configuration** — Full configuration under
   ``plugin.tx_maispace_assets`` with sensible production defaults.

*  **Global Fluid namespace** — The ``ma`` namespace is registered globally; no
   ``{namespace}`` declaration needed in templates.

Compatibility
-------------

*  TYPO3 12.4 LTS and 13.x LTS
*  PHP 8.1 or later
*  ``matthiasmullie/minify ^1.3``
*  ``scssphp/scssphp ^1.12``
