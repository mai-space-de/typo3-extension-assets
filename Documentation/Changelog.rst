.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

1.1.0 (2026-02-26)
==================

New Features
------------

Image ViewHelpers
~~~~~~~~~~~~~~~~~

*  **``srcset`` and ``sizes`` on ``<mai:image>``** — Add the ``srcset`` argument
   (comma-separated widths, e.g. ``"400, 800, 1200"``) to generate a ``srcset``
   attribute without switching to ``<mai:picture>``. Each width is processed independently;
   the actual output pixel width becomes the ``w`` descriptor. Combine with ``sizes`` for a
   fully responsive ``<img>`` tag without art direction.

*  **``fileExtension`` on ``<mai:image>``, ``<mai:picture>``, ``<mai:picture.source>``** —
   Force the output image format per element (e.g. ``fileExtension="webp"``). Overrides
   the new ``image.forceFormat`` TypoScript setting.

*  **``image.forceFormat`` TypoScript setting** — Globally convert all images to a specific
   format (e.g. ``webp``) without adding ``fileExtension`` to every ViewHelper call.

*  **``image.alternativeFormats`` TypoScript setting** — Globally enable automatic
   ``<source type="image/...">`` source sets in ``<mai:picture>`` and ``<mai:picture.source>``
   (e.g. ``alternativeFormats = avif, webp``). Equivalent to adding ``formats="avif, webp"``
   on every picture element.

Asset ViewHelpers
~~~~~~~~~~~~~~~~~

*  **CSP nonce support on ``<mai:css>`` and ``<mai:js>``** — Add a ``nonce`` argument to
   attach a per-request cryptographic nonce to inline ``<style>`` and ``<script>`` tags,
   enabling strict ``Content-Security-Policy`` without ``'unsafe-inline'``.

*  **SRI integrity on ``<mai:css>`` and ``<mai:js>``** — Add ``integrity="true"`` to
   automatically compute a SHA-384 hash of the processed asset and emit an
   ``integrity="sha384-..."`` attribute on the generated ``<link>`` or ``<script>`` tag.
   Add ``crossorigin="anonymous"`` (default) or ``crossorigin="use-credentials"`` alongside
   ``integrity``.

*  **``type="importmap"`` on ``<mai:js>``** — Document explicit support for inline import
   maps via ``type="importmap"`` alongside inline JS content.

Deploy-time Cache Warm-up
~~~~~~~~~~~~~~~~~~~~~~~~~

*  **CLI command ``maispace:assets:warmup``** — New Symfony console command that pre-builds
   the SVG sprite and discovers font registrations for every configured TYPO3 site. Run at
   deploy time after cache clearing to eliminate first-request cold-start latency:

   .. code-block:: bash

       php vendor/bin/typo3 maispace:assets:warmup

Documentation Fixes
-------------------

*  Fixed ViewHelper namespace documented as ``ma`` — the correct namespace is ``mai``.
*  Added ``BeforeImageProcessingEvent`` and ``AfterImageProcessedEvent`` to all event
   overview tables, documentation sections, and example listener counts.

----

1.0.0 (2026-02-26)
==================

Initial release.

New Features
------------

Asset ViewHelpers
~~~~~~~~~~~~~~~~~

*  **CSS ViewHelper** (``mai:css``) — Include CSS from file (EXT: path) or inline Fluid
   content. Supports minification, deferred loading (``media="print"`` swap), inline
   ``<style>`` in ``<head>``, and the ``media`` attribute.

*  **JS ViewHelper** (``mai:js``) — Include JavaScript from file or inline. Supports
   minification, ``defer``, ``async``, ``type="module"``, and footer/head placement.

*  **SCSS ViewHelper** (``mai:scss``) — Compile SCSS to CSS server-side via scssphp
   (pure PHP, no Node.js required). Supports configurable import paths, cache
   auto-invalidation on file change (``filemtime``), and all CSS placement options.

Image ViewHelpers
~~~~~~~~~~~~~~~~~

*  **Image ViewHelper** (``mai:image``) — Render a single ``<img>`` tag from a
   sys_file_reference UID, FAL File/FileReference object, or EXT:/relative path string.
   Uses TYPO3's native ``ImageService`` for processing (resize, crop, WebP conversion).
   Supports lazy loading (``loading="lazy"``), JS-hook lazy-load CSS class, ``fetchpriority``
   attribute, and ``<link rel="preload" as="image">`` injection into ``<head>``.

*  **Picture ViewHelper** (``mai:picture``) — Render a responsive ``<picture>`` element.
   ``<mai:picture.source>`` children define each ``<source>`` tag inline in the template.
   Falls back to a ``<img>`` element using the same image and configurable fallback dimensions.
   Lazy loading, preload, and fetchpriority are propagated to the fallback ``<img>`` and to
   child ``<mai:picture.source>`` elements via the ViewHelper variable container.

*  **Picture Source ViewHelper** (``mai:picture.source``) — Define a single ``<source>`` tag
   inside ``<mai:picture>``. Accepts a ``media`` query, processing dimensions, optional image
   override per breakpoint, and optional ``type`` MIME hint.

*  **Figure ViewHelper** (``mai:figure``) — Wrap any content in a ``<figure>`` with an
   optional ``<figcaption>``. Kept deliberately separate from ``mai:picture`` and ``mai:image``
   for single-responsibility template composition.

SVG Sprite ViewHelper
~~~~~~~~~~~~~~~~~~~~~

*  **SVG Sprite ViewHelper** (``mai:svgSprite``) — Render an ``<svg><use href="...#id">``
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
   *  ``BeforeImageProcessingEvent``
   *  ``AfterImageProcessedEvent``

   All events expose mutable setters so listeners can modify the final output.
   ``BeforeSpriteSymbolRegisteredEvent`` allows filtering, renaming, or vetoing individual
   symbols before they are stored in the registry. ``BeforeImageProcessingEvent`` allows
   modifying processing instructions or skipping processing entirely;
   ``AfterImageProcessedEvent`` allows replacing the ``ProcessedFile``, logging metrics,
   or triggering CDN cache warming.

*  **Example Event Listeners** — Seven documented example listeners (commented out by
   default) demonstrating CSS variable injection, JS configuration injection, SCSS
   size auditing, SVG sprite symbol appending, WebP/AVIF conversion, and image
   metrics logging.

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
