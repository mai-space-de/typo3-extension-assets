.. include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

All settings live under the TypoScript path ``plugin.tx_maispace_assets``.
Individual ViewHelper arguments always take precedence over TypoScript settings.

CSS Settings
============

.. confval-menu::
    :name: css-settings
    :display: table

.. confval:: plugin.tx_maispace_assets.css.minify
    :type: boolean
    :Default: 1

    Minify all CSS assets using ``matthiasmullie/minify``.

    ``0`` disables minification globally. Individual assets can override this with
    the ViewHelper's ``minify`` argument.

.. confval:: plugin.tx_maispace_assets.css.deferred
    :type: boolean
    :Default: 1

    Load external CSS files non-blocking by default using the ``media="print"`` onload-swap
    technique. When ``1``, every ``<mai:css>`` call that produces a ``<link>`` tag will use:

    .. code-block:: html

        <link rel="stylesheet" href="..." media="print" onload="this.media='all'">
        <noscript><link rel="stylesheet" href="..."></noscript>

    Set to ``0`` to use standard ``<link>`` tags. Individual assets can override this with
    the ViewHelper's ``deferred`` argument.

.. confval:: plugin.tx_maispace_assets.css.outputDir
    :type: string
    :Default: ``typo3temp/assets/maispace_assets/css/``

    Output directory for processed CSS files, relative to the TYPO3 public root.
    The directory is created automatically if it does not exist.

.. confval:: plugin.tx_maispace_assets.css.identifierPrefix
    :type: string
    :Default: ``maispace_``

    Prefix prepended to auto-generated asset identifiers (when no ``identifier`` is
    specified on the ViewHelper). The full auto-identifier is:
    ``{prefix}css_{md5(source)}``.

JS Settings
===========

.. confval:: plugin.tx_maispace_assets.js.minify
    :type: boolean
    :Default: 1

    Minify all JS assets using ``matthiasmullie/minify``.

.. confval:: plugin.tx_maispace_assets.js.defer
    :type: boolean
    :Default: 1

    Add the ``defer`` attribute to all external ``<script>`` tags by default.
    Deferred scripts execute after the document is parsed, in order, without blocking rendering.

    Individual assets can override this with the ViewHelper's ``defer`` argument.

.. confval:: plugin.tx_maispace_assets.js.outputDir
    :type: string
    :Default: ``typo3temp/assets/maispace_assets/js/``

    Output directory for processed JS files.

.. confval:: plugin.tx_maispace_assets.js.identifierPrefix
    :type: string
    :Default: ``maispace_``

    Prefix for auto-generated JS asset identifiers.

SCSS Settings
=============

.. confval:: plugin.tx_maispace_assets.scss.minify
    :type: boolean
    :Default: 1

    Use ``OutputStyle::COMPRESSED`` in scssphp when compiling SCSS. This removes all
    whitespace from the compiled CSS — no redundant second minification pass is needed.

.. confval:: plugin.tx_maispace_assets.scss.cacheLifetime
    :type: integer
    :Default: 0

    Cache lifetime in seconds for compiled SCSS. ``0`` means permanent until the
    TYPO3 page cache is flushed. For file-based SCSS, the cache is additionally
    invalidated automatically when the source file changes (via ``filemtime``).

.. confval:: plugin.tx_maispace_assets.scss.defaultImportPaths
    :type: string
    :Default: *(empty)*

    Comma-separated list of additional import paths for all SCSS compilation.
    Supports ``EXT:`` notation. The source file's directory is always available
    automatically. Per-asset paths can be added via the ``importPaths`` argument.

    Example:

    .. code-block:: typoscript

        plugin.tx_maispace_assets.scss.defaultImportPaths = EXT:theme/Resources/Private/Scss/Partials

Image Settings
==============

.. confval:: plugin.tx_maispace_assets.image.lazyloading
    :type: boolean
    :Default: 1

    Add ``loading="lazy"`` to all images rendered by ``<mai:image>`` and the fallback
    ``<img>`` inside ``<mai:picture>`` by default.

    ``0`` disables lazy loading globally. Override per image with the ViewHelper's
    ``lazyloading`` argument.

.. confval:: plugin.tx_maispace_assets.image.lazyloadWithClass
    :type: string
    :Default: *(empty)*

    CSS class name added alongside ``loading="lazy"`` on all lazily-loaded images.
    When set, this also enables lazy loading (setting a class implies lazy loading).
    Useful for JS-based lazy loaders such as `lazysizes <https://github.com/aFarkas/lazysizes>`__.

    Override per image with the ViewHelper's ``lazyloadWithClass`` argument.

    Example:

    .. code-block:: typoscript

        plugin.tx_maispace_assets.image.lazyloadWithClass = lazyload

.. confval:: plugin.tx_maispace_assets.image.forceFormat
    :type: string
    :Default: *(empty)*

    Force all images processed by ``<mai:image>``, the ``<mai:picture>`` fallback ``<img>``,
    and ``<mai:picture.source>`` (when ``formats`` is not set) to a specific output format.

    Leave empty (default) to keep the source file's format. The image processor (GraphicsMagick
    or ImageMagick) must support the target format.

    Override per image using the ViewHelper's ``fileExtension="webp"`` argument.

    .. code-block:: typoscript

        # Convert all images to WebP globally
        plugin.tx_maispace_assets.image.forceFormat = webp

.. confval:: plugin.tx_maispace_assets.image.alternativeFormats
    :type: string
    :Default: *(empty)*

    Comma-separated list of target formats in preference order (most capable first).
    When set, ``<mai:picture>`` and ``<mai:picture.source>`` automatically render
    one ``<source type="image/...">`` tag per format before the fallback element,
    allowing browsers to pick the best format they support.

    Leave empty (default) to disable automatic format source sets globally. Override
    per element using the ViewHelper's ``formats="avif, webp"`` argument.

    .. code-block:: typoscript

        # Serve AVIF to browsers that support it, WebP as second choice, original as fallback
        plugin.tx_maispace_assets.image.alternativeFormats = avif, webp

Lottie Settings
===============

.. confval:: plugin.tx_maispace_assets.lottie.playerSrc
    :type: string
    :Default: *(empty)*

    URL or ``EXT:`` path to the ``@lottiefiles/lottie-player`` JavaScript library. When set,
    ``<mai:lottie>`` automatically registers this script via TYPO3's ``AssetCollector`` as a
    ``type="module"`` script (non-blocking) the first time a Lottie ViewHelper is rendered on
    a page.

    Accepts an external CDN URL or a local path using ``EXT:`` notation.

    When empty (default), no player script is registered automatically. Either configure this
    setting or pass ``playerSrc`` directly on each ``<mai:lottie>`` call. Pass ``playerSrc=""``
    on a ViewHelper to skip registration even when this setting is configured.

    .. code-block:: typoscript

        plugin.tx_maispace_assets.lottie.playerSrc = https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js

        # Or a locally bundled copy:
        # plugin.tx_maispace_assets.lottie.playerSrc = EXT:theme/Resources/Public/Vendor/lottie-player.js

Font Settings
=============

.. confval:: plugin.tx_maispace_assets.fonts.preload
    :type: boolean
    :Default: 1

    Global switch for web font preloading. When ``1``, the font registry emits
    ``<link rel="preload" as="font" crossorigin>`` tags for all registered fonts that
    do not explicitly set ``'preload' => false``.

    Set to ``0`` to suppress all font preload tags site-wide. Useful when fonts are
    handled by a CDN or HTTP/2 push header instead.

    See :ref:`configuration-fontregistry` for how to register fonts from your extension.

SVG Sprite Settings
===================

Critical CSS & JS Settings
==========================

.. confval:: plugin.tx_maispace_assets.criticalCss.enable
    :type: boolean
    :Default: 1

    Enable the ``CriticalCssInlineMiddleware`` which automatically injects cached critical
    assets into the ``<head>``.

.. confval:: plugin.tx_maispace_assets.criticalCss.layer
    :type: string
    :Default: *(empty)*

    CSS layer name for the injected critical CSS. If set, the injected ``<style>`` content
    is wrapped in ``@layer {name} { ... }``.

.. confval:: plugin.tx_maispace_assets.criticalCss.mobile.width
    :type: integer
    :Default: 375

    Viewport width in CSS pixels used for mobile extraction.

.. confval:: plugin.tx_maispace_assets.criticalCss.mobile.height
    :type: integer
    :Default: 667

    Viewport height in CSS pixels.

.. confval:: plugin.tx_maispace_assets.criticalCss.mobile.maxWidth
    :type: integer
    :Default: 767

    Upper boundary (inclusive) for the mobile media query. Screens <= this width receive
    the mobile critical CSS.

.. confval:: plugin.tx_maispace_assets.criticalCss.desktop.width
    :type: integer
    :Default: 1440

    Viewport width for desktop extraction.

.. confval:: plugin.tx_maispace_assets.criticalCss.desktop.height
    :type: integer
    :Default: 900

    Viewport height for desktop extraction.

.. confval:: plugin.tx_maispace_assets.criticalCss.desktop.minWidth
    :type: integer
    :Default: 768

    Lower boundary (inclusive) for the desktop media query.

.. confval:: plugin.tx_maispace_assets.criticalCss.chromiumBin
    :type: string
    :Default: *(empty)*

    Absolute path to the Chromium/Chrome binary. If empty, common paths are tried automatically.
    Can be overridden via the CLI command using the ``--chromium-bin`` option.

.. _configuration-spriteiconregistry:

Symbol Registration (SpriteIcons.php)
--------------------------------------

SVG symbols are registered declaratively — not via the ViewHelper. Drop a
``Configuration/SpriteIcons.php`` file into any loaded extension and return an array
where each key is the symbol ID and the value is a configuration array:

.. code-block:: php

    <?php
    // EXT:my_sitepackage/Configuration/SpriteIcons.php
    declare(strict_types=1);

    return [
        // Global icon — available on all sites
        'icon-arrow' => [
            'src' => 'EXT:my_sitepackage/Resources/Public/Icons/arrow.svg',
        ],
        // Site-scoped icon — only in the sprite for site "brand-a"
        'icon-brand-logo' => [
            'src'   => 'EXT:brand_a/Resources/Public/Icons/logo.svg',
            'sites' => ['brand-a'],
        ],
    ];

The registry auto-discovers ``Configuration/SpriteIcons.php`` across all loaded
extensions on the first sprite request. No ``ext_localconf.php`` boilerplate is needed.
Symbol IDs must be unique across all extensions; later-loaded extensions' IDs take
precedence on conflict (last-write-wins).

The optional ``'sites'`` key accepts an array of TYPO3 site identifiers. See
:ref:`configuration-multisite` for the full multi-site scoping documentation.

Listen to :ref:`event-before-sprite-symbol-registered` to filter, rename, or veto
individual symbols before they are stored in the registry.

.. confval:: plugin.tx_maispace_assets.svgSprite.routePath
    :type: string
    :Default: ``/maispace/sprite.svg``

    URL path at which ``SvgSpriteMiddleware`` intercepts requests and serves the
    assembled SVG sprite document. The ViewHelper reads this setting to build the
    ``href`` attribute in ``<use href="...#symbol-id">`` references.

    The path must not conflict with any existing TYPO3 page slug.

    .. code-block:: typoscript

        plugin.tx_maispace_assets.svgSprite.routePath = /assets/icons.svg

    The middleware is positioned after ``site-resolver`` (so TypoScript is available)
    and before ``page-resolver`` (so no page lookup is triggered for sprite requests).
    The sprite response includes:

    * ``Content-Type: image/svg+xml; charset=utf-8``
    * ``Cache-Control: public, max-age=31536000, immutable``
    * ``ETag`` — derived from a SHA-1 of the sprite content; enables 304 responses.
    * ``Vary: Accept-Encoding``

.. confval:: plugin.tx_maispace_assets.svgSprite.symbolIdPrefix
    :type: string
    :Default: ``icon-``

    Naming convention prefix. This value is not enforced by the registry —
    symbol IDs are taken verbatim from the array keys in ``SpriteIcons.php``.
    The setting is documented here as a convention reference for teams that want
    a consistent prefix across extensions.

.. confval:: plugin.tx_maispace_assets.svgSprite.cache
    :type: boolean
    :Default: 1

    Cache the assembled SVG sprite in the ``maispace_assets`` cache. Set to ``0``
    to rebuild the sprite on every request (useful during development, but not for production).

    The cache key incorporates the SHA-1 of every registered symbol's ID, absolute
    source path, and file modification time. Any change to an SVG source file
    automatically produces a new cache entry without a manual cache flush.

Debug Mode
==========

When a backend user is logged in **and** the URL contains ``?debug=1``, all minification
and deferral is disabled so the original, readable assets are served:

.. code-block:: typoscript

    [backend.user.isLoggedIn && request && traverse(request.getQueryParams(), 'debug') > 0]
        plugin.tx_maispace_assets {
            css {
                minify = 0
                deferred = 0
            }
            js {
                minify = 0
                defer = 0
            }
            scss {
                minify = 0
            }
        }
    [global]

This condition is already included in the extension's ``setup.typoscript`` and is active
automatically. You do not need to add it manually.

Full Example Configuration
==========================

.. code-block:: typoscript

    @import 'EXT:maispace_assets/Configuration/TypoScript/setup.typoscript'

    plugin.tx_maispace_assets {
        css {
            minify = 1
            deferred = 1
            outputDir = typo3temp/assets/maispace_assets/css/
            identifierPrefix = mysite_
        }
        js {
            minify = 1
            defer = 1
            outputDir = typo3temp/assets/maispace_assets/js/
        }
        scss {
            minify = 1
            cacheLifetime = 0
            defaultImportPaths = EXT:theme/Resources/Private/Scss/Partials
        }
        image {
            lazyloading = 1
            lazyloadWithClass =
            forceFormat =
            alternativeFormats =
        }
        fonts {
            preload = 1
        }
        lottie {
            playerSrc = https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js
        }
        svgSprite {
            routePath = /maispace/sprite.svg
            symbolIdPrefix = icon-
            cache = 1
        }
    }

.. _configuration-cli:

CLI Command — Deploy-time Cache Warm-up
=======================================

The extension ships a Symfony console command that pre-builds SVG sprites and
discovers font registrations for every configured TYPO3 site. Run it at deploy
time **after** clearing the TYPO3 cache to eliminate first-request cold-start latency:

.. code-block:: bash

    php vendor/bin/typo3 maispace:assets:warmup

What the command does:

1. **Font discovery** — triggers ``FontRegistry::discover()`` which loads all
   ``Configuration/Fonts.php`` files from every loaded extension.
2. **SVG sprite build** — calls ``SpriteIconRegistry::buildSprite($siteIdentifier)``
   for each configured TYPO3 site, writing the result to the ``maispace_assets``
   caching framework cache.

The command is idempotent — running it multiple times is safe. Unchanged sprites are
not rebuilt because the cache key encodes the content hash of each symbol.

Example output:

.. code-block:: text

    Font registry: 3 font(s) discovered across all extensions.
      · my-font-regular
      · my-font-bold
      · icon-font

    Building SVG sprite for 2 site(s)
      ✓  brand-a — 18 symbol(s) cached
      ✓  brand-b — 12 symbol(s) cached

     [OK] All sites warmed up successfully.

.. _configuration-fontregistry:

Critical CSS & JS Extraction
============================

Extract per-page critical above-the-fold CSS and JS for every configured TYPO3 site,
language, and workspace:

.. code-block:: bash

    # All sites, all pages, all languages (default)
    php vendor/bin/typo3 maispace:assets:critical:extract

    # Specific site only
    php vendor/bin/typo3 maispace:assets:critical:extract --site=main

    # Specific workspace (e.g. 1 = Draft)
    php vendor/bin/typo3 maispace:assets:critical:extract --workspace=1

    # Specific pages only
    php vendor/bin/typo3 maispace:assets:critical:extract --pages=1,12,42

    # Custom Chromium binary
    php vendor/bin/typo3 maispace:assets:critical:extract --chromium-bin=/usr/bin/chromium

The command automatically iterates through all configured TYPO3 sites and recursively
collects all standard pages (doktype=1) from each site's root. For each page, it
iterates through all configured languages and viewports (mobile/desktop).

The extracted assets are stored in the TYPO3 caching framework and automatically injected
by the ``CriticalCssInlineMiddleware`` if enabled.

Font Registration (Fonts.php)
=============================

Web fonts are registered declaratively in ``Configuration/Fonts.php``. The font registry
auto-discovers this file from all loaded extensions and emits
``<link rel="preload" as="font" crossorigin>`` tags in ``<head>`` before stylesheets render.

.. code-block:: php

    <?php
    // EXT:my_sitepackage/Configuration/Fonts.php
    declare(strict_types=1);

    return [
        'my-font-regular' => [
            'src'  => 'EXT:my_sitepackage/Resources/Public/Fonts/MyFont-Regular.woff2',
            // 'type' auto-detected from extension: .woff2 → font/woff2
        ],
        'my-font-bold' => [
            'src'     => 'EXT:my_sitepackage/Resources/Public/Fonts/MyFont-Bold.woff2',
            'preload' => false,   // opt out of preloading for this font only
        ],
    ];

**Configuration keys:**

``src`` (string, required)
    ``EXT:`` path or absolute path to the font file.

``type`` (string, optional)
    MIME type. Auto-detected from the file extension when omitted.
    Supported: ``font/woff2``, ``font/woff``, ``font/ttf``, ``font/otf``.

``preload`` (bool, optional, default ``true``)
    Set to ``false`` to register the font without emitting a preload tag.

``sites`` (array, optional)
    List of TYPO3 site identifiers. When present, the font is only preloaded on matching
    sites. See :ref:`configuration-multisite`.

.. _configuration-multisite:

Multi-site Scoping
==================

When a single TYPO3 instance hosts multiple sites (e.g. ``brand-a`` and ``brand-b``), it
wastes bandwidth to preload all fonts and include all icons on every site. Both
``SpriteIcons.php`` and ``Fonts.php`` support an optional ``'sites'`` key to restrict an
entry to specific sites.

**How it works:**

* Entries **without** ``'sites'`` are global — included on every site (backwards compatible).
* Entries **with** ``'sites'`` are only included when the current request's site identifier
  matches one of the listed values.
* The site identifier corresponds to the folder name under ``config/sites/{identifier}/``.

.. code-block:: php

    return [
        // Global — served to all sites
        'icon-arrow' => [
            'src' => 'EXT:shared/Resources/Public/Icons/arrow.svg',
        ],

        // Only served on site "brand-a"
        'icon-brand-a-logo' => [
            'src'   => 'EXT:brand_a/Resources/Public/Icons/logo.svg',
            'sites' => ['brand-a'],
        ],

        // Served on two sites (staging mirrors production identifier)
        'icon-brand-b-logo' => [
            'src'   => 'EXT:brand_b/Resources/Public/Icons/logo.svg',
            'sites' => ['brand-b', 'brand-b-staging'],
        ],
    ];

**SVG sprites:** Each site gets its own independently cached sprite. The sprite URL is the
same across sites (configured via ``svgSprite.routePath``); the middleware serves
site-specific content transparently using the ETag mechanism for efficient browser caching.

**Font preloads:** Each page request emits only the preload tags applicable to the current
site — no cross-site font URLs are ever sent to the browser.
