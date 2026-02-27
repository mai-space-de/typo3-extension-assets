.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

1.3.0 (2026-02-27)
==================

New Features
------------

Image ViewHelpers
~~~~~~~~~~~~~~~~~

*  **``preloadMedia`` on ``<mai:image>`` and ``<mai:picture>``** —
   ``ImageRenderingService::addImagePreloadHeader()`` already accepted an optional
   ``$media`` parameter, but neither ViewHelper exposed it. The new ``preloadMedia``
   argument threads this value through so the emitted ``<link rel="preload">`` carries
   a ``media`` attribute. This lets the browser skip preloading an image that is
   irrelevant at the current viewport size — critical for responsive hero images where
   a 1920 px desktop variant must not be prefetched on mobile.

   .. code-block:: html

       <mai:image image="{heroDesktop}" alt="{alt}" width="1920"
                 preload="true" preloadMedia="(min-width: 768px)" lazyloading="false" />

       <mai:picture image="{hero}" alt="{alt}" width="1920" lazyloading="false"
                   preload="true" preloadMedia="(min-width: 768px)" fetchPriority="high">
           <mai:picture.source media="(min-width: 768px)" width="1920" />
           <mai:picture.source media="(max-width: 767px)" width="600" />
       </mai:picture>

*  **``imgClass``, ``imgId``, ``imgTitle``, ``imgAdditionalAttributes`` on ``<mai:picture>``**
   — The ``class`` and ``additionalAttributes`` arguments on ``<mai:picture>`` always
   targeted the outer ``<picture>`` element, but there was no way to set attributes on the
   fallback ``<img>`` independently. The four new ``img*`` arguments pass values directly
   to ``renderImgTag()``:

   .. code-block:: html

       <mai:picture image="{img}" alt="{alt}" width="1200"
                   class="picture-wrapper" imgClass="content-image" imgId="hero-img">
           <mai:picture.source media="(min-width: 768px)" width="1200" />
       </mai:picture>

Bug Fixes
---------

*  **``additionalAttributes`` applied to both ``<picture>`` and ``<img>``** — The
   ``additionalAttributes`` argument was documented as targeting the ``<picture>`` element,
   but the code also passed it to ``renderImgTag()``, causing attributes to appear on both
   elements. ``additionalAttributes`` now applies only to ``<picture>`` (matching the
   documentation). Use the new ``imgAdditionalAttributes`` argument to target the ``<img>``.

SCSS ViewHelper
~~~~~~~~~~~~~~~

*  **``nonce``, ``integrity``, ``crossorigin`` on ``<mai:scss>``** —
   ``AssetProcessingService::registerCompiledCss()`` already called ``resolveNonce()`` and
   ``buildIntegrityAttrs()`` on the arguments array, but ``ScssViewHelper`` never registered
   those arguments, making them unreachable from templates. They are now exposed:

   .. code-block:: html

       <!-- SRI hash on the compiled stylesheet -->
       <mai:scss src="EXT:theme/Resources/Private/Scss/main.scss" integrity="true" />

       <!-- Explicit nonce override (normally auto-detected from TYPO3 request) -->
       <mai:scss identifier="critical" priority="true" inline="true" nonce="{customNonce}">
           body { margin: 0; }
       </mai:scss>

----

1.2.0 (2026-02-27)
==================

New Features
------------

New ViewHelpers
~~~~~~~~~~~~~~~

*  **``<mai:hint>``** — Emit resource hint ``<link>`` tags into ``<head>`` from Fluid templates.
   Supports ``preconnect``, ``dns-prefetch``, ``modulepreload``, ``prefetch``, and ``preload``.
   Full attribute support: ``as``, ``type``, ``crossorigin``, ``media``.
   ``modulepreload`` defaults to ``as="script"`` per spec. Injected via
   ``PageRenderer::addHeaderData()``.

*  **``<mai:svgInline>``** — Embed an SVG file directly as inline ``<svg>`` markup.
   Unlike ``<mai:svgSprite>``, the full SVG element is read from disk and injected into the
   HTML response — enabling CSS ``fill: currentColor`` styling and JavaScript-driven animations.
   Supports overriding ``class``, ``width``, ``height``, ``aria-hidden``, ``aria-label``,
   ``role``, and ``<title>`` injection. Results are cached in the TYPO3 caching framework.

*  **``<mai:lottie>``** — Render a Lottie JSON animation via the ``<lottie-player>`` web
   component. Accepts ``EXT:`` paths, public-relative paths, or external CDN URLs for the
   animation JSON. Optionally registers the ``@lottiefiles/lottie-player`` player script via
   ``AssetCollector`` as a ``type="module"`` script. Arguments: ``autoplay``, ``loop``,
   ``controls``, ``speed``, ``direction``, ``mode`` (``bounce``), ``renderer``
   (``svg``/``canvas``/``html``), ``background``, ``width``, ``height``, ``class``,
   ``playerSrc``, ``playerIdentifier``, ``additionalAttributes``. Player URL configurable
   globally via TypoScript: ``plugin.tx_maispace_assets.lottie.playerSrc``.

Asset ViewHelpers
~~~~~~~~~~~~~~~~~

*  **External CDN passthrough** on ``<mai:css>`` and ``<mai:js>`` — ``src="https://..."``
   is now supported. External URLs are registered directly with AssetCollector without file
   read, minification, cache write, or PSR-14 event dispatch. All existing placement
   arguments (``priority``, ``media``, ``defer``, ``async``) work as normal.

*  **``integrityValue`` argument** on ``<mai:css>`` and ``<mai:js>`` — Pass a pre-computed
   SRI hash string (e.g. ``sha384-abc123...``) to emit an ``integrity`` attribute on external
   CDN assets where the hash cannot be computed at render time.

*  **``nomodule`` argument** on ``<mai:js>`` — Emit the ``nomodule`` attribute for legacy
   differential loading. Automatically suppresses ``defer`` and ``async`` (which are
   incompatible with ``nomodule`` scripts).

Bug Fixes
---------

*  **``type="importmap"`` correctness** — Import maps in ``<mai:js type="importmap">`` are
   now handled per spec: the JSON content is never minified (the minifier was mangling the
   JSON), never given a ``defer`` or ``async`` attribute (importmaps must parse synchronously),
   and always placed in ``<head>`` (``priority=true`` forced). Previously this combination
   produced broken output.

Image ViewHelpers
~~~~~~~~~~~~~~~~~

*  **``quality`` argument** on ``<mai:image>``, ``<mai:picture>``, and ``<mai:picture.source>``
   — Pass an integer (1–100) to control the lossy output quality (JPEG, WebP, AVIF) passed
   to ImageMagick/GraphicsMagick via TYPO3's ``ImageService``. ``0`` (default) uses the
   processor default (~75–85). Quality is included in the image processing cache key to
   prevent stale results across quality changes.

----

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
