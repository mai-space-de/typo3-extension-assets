.. _static-file-cache-architecture:

=============================
Static File Cache Architecture
=============================

.. rst-class:: opening

    mai_assets implements a native, multi-layer static file cache that stores compiled,
    minified, and compressed asset variants on the filesystem. No third-party cache
    extension (e.g. ``staticfilecache`` or ``nginx-cache``) is required — all caching
    is built directly into the asset pipeline using TYPO3's core caching framework and
    convention-based file storage.

Architecture Overview
=====================

The static file cache operates at three distinct layers, each serving a different
purpose in the asset delivery chain:

.. code-block:: text

    ┌─────────────────────────────────────────────────────────────────────────┐
    │                    LAYER 1: FILE COMPILATION CACHE                      │
    │                  typo3temp/assets/mai_assets/compiled/                  │
    │                                                                         │
    │  Source (.scss/.css/.js) → Compile (SCSS) → Minify → Write to disk     │
    │  Cache key: sha256(source_file) + processing flags                     │
    └──────────────┬──────────────────────────────────────────────────────────┘
                   │
                   ▼
    ┌─────────────────────────────────────────────────────────────────────────┐
    │                    LAYER 2: PRE-COMPRESSION CACHE                        │
    │                  typo3temp/assets/mai_assets/compiled/*.{gz,br}         │
    │                                                                         │
    │  Compiled file → gzip (level 6) → .gz                                  │
    │               → brotli (level 6) → .br                                 │
    └──────────────┬──────────────────────────────────────────────────────────┘
                   │
                   ▼
    ┌─────────────────────────────────────────────────────────────────────────┐
    │                    LAYER 3: DELIVERY OPTIMISATION                        │
    │                                                                         │
    │  ┌─────────────────────┐   ┌───────────────────────────────────┐       │
    │  │ TYPO3 AssetCollector│   │ HTTP 103 Early Hints Middleware    │       │
    │  │ (registers CSS/JS)  │   │ (preload / preconnect candidates)  │       │
    │  └─────────────────────┘   └───────────────────────────────────┘       │
    └─────────────────────────────────────────────────────────────────────────┘

Layer 1: Compiled Asset Cache
==============================

Storage Path

All compiled assets are written to a single, deterministic directory::

    typo3temp/assets/mai_assets/compiled/

The path is defined as a class constant in two places:

- ``CompiledAssetPublisher::PUBLIC_CACHE_DIR`` — primary publisher for CSS/SCSS
  (``Classes/Service/CompiledAssetPublisher.php``)
- ``AbstractAssetProcessor::CACHE_DIR`` — fallback for internal CSS/JS processor
  caching (``Classes/Processing/AbstractAssetProcessor.php``)
- ``JsViewHelper`` — minified JS cache
  (``Classes/ViewHelpers/Asset/JsViewHelper.php``)

Cache Key Strategy

Cache keys are **content-hash based**, ensuring automatic invalidation when source
files change — no manual cache flush is required.

.. list-table:: Cache Key Components
    :header-rows: 1
    :widths: 30 70

    * - Component
      - Description
    * - ``sha256(source_file)``
      - Cryptographic hash of the full source file content.
    * - ``:scss=1|0``
      - Whether SCSS compilation was applied.
    * - ``:min=1|0``
      - Whether minification was applied.

The resulting key is an ``md5()`` of the concatenated components, producing a
deterministic, collision-safe filename::

    <md5_hash>.css       # Compiled stylesheet
    <md5_hash>.js        # Minified script

The SHA-256 of the source file is always part of the hash input, so an editor
modifying a ``.scss`` file directly changes the hash on the next page render.

Compilation Pipeline

The ``CompiledAssetPublisher`` (``Classes/Service/CompiledAssetPublisher.php``)
is the single source of truth for compiling CSS/SCSS sources. Its flow:

.. code-block:: text

    publishStylesheet(source, minify?)
        │
        ├── 1. Determine source extension (.css or .scss)
        ├── 2. Check if SCSS processing is enabled (ExtensionConfiguration)
        ├── 3. Check if minification is needed
        ├── 4. If neither: return source path immediately (short-circuit)
        │
        ├── 5. Build cache hash from sha256(source) + flags
        ├── 6. Check if cache file already exists
        │       ├── YES → return cached path
        │       └── NO  → continue
        │
        ├── 7. Read source content
        ├── 8. SCSS compile (if applicable)
        ├── 9. Minify (if applicable)
        ├── 10. mkdir_deep(cache dir)
        ├── 11. file_put_contents(cache file)
        └── 12. Return absolute cache file path

Plugin hooks and content modifiers can inject themselves into the pipeline via
PSR-14 events (see :ref:`events`).

Short-Circuit Path
------------------

When a source file is plain CSS and minification is disabled, the publisher
returns the original source path unchanged — no cache write occurs. This avoids
unnecessary disk I/O for already-optimised files.

Layer 2: Pre-Compression Cache
==============================

After a compiled asset file is written, the ``CompressionProcessor``
(``Classes/Processing/CompressionProcessor.php``) optionally creates
pre-compressed variants:

.. code-block:: text

    compressFile(filePath)
        │
        ├── 1. Read compiled file content
        ├── 2. gzencode(content, level=6)     → file.css.gz
        ├── 3. (if brotli enabled + available) → file.css.br
        └──   brotli_compress(content, level=6)

The compressed files are written **alongside** the uncompressed file in the same
cache directory::

    typo3temp/assets/mai_assets/compiled/
        ├── <hash>.css          # Uncompressed
        ├── <hash>.css.gz       # Gzip (level 6)
        └── <hash>.css.br       # Brotli (level 6, if enabled)

The web server (Apache or Nginx) is configured to serve these pre-compressed
variants when the client advertises ``Accept-Encoding: gzip`` or ``br``. See the
:ref:`installation` section for configuration examples.

Compression is configured via ``ExtensionConfiguration``:

.. code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = [
        'enableCompression'  => true,   // Master switch
        'compressionLevel'   => 6,      // 1–9 (higher = smaller but slower)
        'enableBrotli'       => true,   // Requires ext-brotli
    ];

Layer 3: TYPO3 Caching Framework Caches
========================================

Beyond the filesystem cache for compiled files, the extension registers three
additional caches in the TYPO3 caching framework:

.. list-table:: TYPO3 Caches Registered by mai_assets
    :header-rows: 1
    :widths: 25 25 15 35

    * - Cache Identifier
      - Backend
      - Groups
      - Purpose
    * - ``mai_assets_above_fold``
      - ``Typo3DatabaseBackend``
      - ``system``
      - Per-page, per-viewport-bucket critical content element UIDs
    * - ``mai_assets``
      - ``FileBackend``
      - ``pages``
      - General assets cache (SVG inline output, processed content)
    * - ``mai_assets_early_hints``
      - ``Typo3DatabaseBackend``
      - ``pages``
      - HTTP 103 Early Hints manifest per page+language

1. Above-Fold Cache (``mai_assets_above_fold``)
------------------------------------------------

.. code-block:: php

    // Registered in ext_localconf.php
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mai_assets_above_fold'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend'  => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'groups'   => ['system'],
    ];

Stores the set of content element UIDs that are visible in the viewport per page
and viewport bucket. Managed by ``AboveFoldCacheService``
(``Classes/Cache/AboveFoldCacheService.php``).

Cache keys:

- ``page_{pageUid}_{bucket}`` — array of critical UIDs for a page/bucket.
- ``buckets_{pageUid}`` — list of bucket names that have data.
- ``reset_{pageUid}`` — integer reset timestamp.
- ``ratelimit_{md5(ip)}`` — IP-based rate limit counter for the report endpoint.

All entries are tagged with ``mai_assets`` and ``pageId_{pageUid}``.

2. General Assets Cache (``mai_assets``)
-----------------------------------------

.. code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mai_assets'] ??= [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend'  => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
        'groups'   => ['pages'],
    ];

Used for general cached asset output (e.g. compiled SVG inline content from
``<mai:svg.inline>`` that is cached by file hash). The ``FileBackend`` stores
data on disk under ``typo3temp/var/cache/code/mai_assets/``.

3. Early Hints Manifest Cache (``mai_assets_early_hints``)
-----------------------------------------------------------

.. code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mai_assets_early_hints'] ??= [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend'  => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'groups'   => ['pages'],
    ];

Stores the serialised set of ``EarlyHintCandidate`` objects keyed by page +
language. Managed by ``EarlyHintCacheService``
(``Classes/EarlyHints/EarlyHintCacheService.php``).

Cache key format: ``earlyhints_{pageUid}_{languageUid}``.

This cache enables the ``EarlyHintsMiddleware`` to emit HTTP 103 Early Hint
responses **before** the full page render completes, and even on cache hits
where the PHP process never enters the full rendering pipeline.

Cache Invalidation Strategy
===========================

The extension uses three complementary invalidation mechanisms:

1. Content-Hash Automatic Invalidation
---------------------------------------

The compiled file cache (Layer 1) is **self-invalidating** — the cache key is
derived from the source file's SHA-256 hash. When the source changes, the hash
changes, producing a new cache file. The old file becomes orphaned and is
cleaned up by TYPO3's garbage collection or the next ``typo3`` CLI cache
flush command.

No explicit invalidation logic is needed for source file edits.

2. Event-Driven Page Cache Invalidation
----------------------------------------

When observer data changes for a page (new visitor reports different critical
UIDs), the ``AboveFoldReportMiddleware`` flushes the TYPO3 page cache for that
page:

.. code-block:: php

    $this->cacheManager->flushCachesByTag('pageId_' . $pageUid);

This ensures the next request produces a fresh render with updated criticality
decisions.

3. DataHandler-Triggered Reset
-------------------------------

When an editor saves, moves, or hides a content element, the
``ContentElementSaveHook`` (``Classes/Hook/ContentElementSaveHook.php``):

1. Clears all above-fold cache entries for the affected page
2. Bumps the reset timestamp for the page
3. Flushes the TYPO3 page cache for the affected page

The ``CriticalCacheInvalidationListener`` (``Classes/EventListener/CriticalCacheInvalidationListener.php``)
listens to ``AfterCriticalUidsUpdatedEvent``. When
``PageOptimizationReadinessService::isReady()`` reports that all viewport buckets
have observer data, it delegates to ``InvalidationService`` to flush TYPO3 page
cache tags, purge static HTML files, and clear early-hints manifests so the next
request re-renders optimised HTML before static file cache write.

Early Hints (HTTP 103) Pipeline
================================

The Early Hints system is fully automatic and operates independently of the
compiled file cache:

.. code-block:: text

    Request arrives
         │
         ├── EarlyHintsMiddleware (process method)
         │       │
         │       ├── Is GET request + not Development context?
         │       │       │
         │       │       ├── YES → Load cached Early Hint candidates
         │       │       │         from EarlyHintCacheService
         │       │       │         │
         │       │       │         └── Candidates exist?
         │       │       │               ├── YES → emit Link: headers as HTTP 103
         │       │       │               └── NO  → continue
         │       │       │
         │       │       └── NO → continue (no hints)
         │       │
         │       └── Pass request to next middleware
         │
         └── Page renders (TSFE → ViewHelpers)
                 │
                 └── ViewHelpers and Collectors add candidates
                     to EarlyHintCandidateCollector (singleton)
                         │
                         └── AfterCacheableContentIsGeneratedEvent
                             → EarlyHintManifestListener stores manifest

The pipeline collects candidates from:

- ``CssViewHelper`` — always registers ``rel=preload, as=style`` for every CSS
  asset (compiled external path), whether critical or not.
- ``JsViewHelper`` — registers ``rel=modulepreload`` (for ES6 modules) or
  ``rel=preload, as=script`` only when the script is marked critical.
- ``ResponsiveImageViewHelper`` — registers ``rel=preload, as=image`` for
  critical images (best format: AVIF or WebP).
- ``PictureViewHelper`` — same as ``ResponsiveImageViewHelper``.
- ``FontPreloadCollector`` — always registers font preload candidates for fonts
  declared in ``Configuration/Fonts.php``.
- ``ExtensionConfigurationDiscovery`` — registers ``rel=preconnect`` candidates
  from ``Configuration/Preconnect.php``.

Early Hints are **disabled in Development context** to avoid issues with
Apache ``mod_proxy_fcgi`` auto-promoting ``Link:`` headers to HTTP 103, which
can cause downstream proxies to fail.

No Third-Party Cache Extension
===============================

The architecture explicitly avoids third-party static file cache extensions
(such as ``lochmueller/staticfilecache`` or ``SupSeven/staticfilecache``) for
the following reasons:

.. list-table:: Design Decisions
    :header-rows: 1
    :widths: 30 70

    * - Decision
      - Rationale
    * - **Native TYPO3 caches**
      - TYPO3's ``VariableFrontend`` + ``Typo3DatabaseBackend`` / ``FileBackend``
        provide all the cache semantics needed. A third-party cache extension
        would duplicate this infrastructure.
    * - **Custom content-hash keying**
      - The compiled asset cache uses SHA-256 of source content + processing
        flags. Third-party cache extensions typically cache full HTML output,
        not individual asset files, and cannot provide this granularity.
    * - **Pre-compression integrated**
      - Gzip and Brotli compression are tightly coupled to the compilation
        pipeline — the compressed files are generated immediately after the
        compiled file is written, before any cache extension sees it.
    * - **Early Hints built in**
      - HTTP 103 Early Hints are handled by a dedicated middleware and cache
        manifest within the extension. A static file cache extension cannot
        integrate with the TYPOSEarly Hints middleware without additional
        bridging code.
    * - **Observer-driven criticality**
      - The above-fold detection system (IntersectionObserver → API → cache →
        criticality resolution) is unique to this project. No existing cache
        extension supports this workflow out of the box.
    * - **No HTML output caching**
      - The static file cache caches **assets** (CSS, JS, images), not full
        HTML pages. TYPO3's built-in full-page cache already handles HTML
        caching. An HTML-caching extension would add complexity without value.

In short, the native pipeline is purpose-built for asset optimisation. HTML
page caching is left to TYPO3's own ``cache_pages`` and related core caches.

Readiness Gate: Warm-Up Model
==============================

The extension operates an intentional **warm-up model** for criticality-based
optimisation:

1. **First request** — No observer data exists. All assets are served as
   external files (no inlining, no priority hints). The observer script is
   injected into the page.

2. **Observer report** — After the user's browser loads the page, the
   ``IntersectionObserver`` script posts visible content element UIDs to
   ``POST /api/mai-assets/above-fold-report``.

3. **Subsequent requests** — Observer data is available. Critical CSS is
   inlined, critical scripts get ``fetchpriority="high"``, images get
   ``loading="eager"``, and Early Hints are emitted on the next uncached
   response.

4. **CLI warm-up** — The ``maispace:assets:warmup`` command pre-compiles
   configured assets before the first request, ensuring the filesystem cache
   is populated at deploy time. Observer data still needs real user visits.

.. code-block:: bash

    # Pre-compile configured assets (SCSS, CSS, JS)
    vendor/bin/typo3 maispace:assets:warmup

Request Flow
=============

The following sequence diagram shows the complete asset delivery flow for a
representative page request:

.. code-block:: text

    Browser                  Apache/Nginx              TYPO3/EarlyHintsMW       AssetPipeline
    │                          │                          │                       │
    │── GET /page ──────────► │                          │                       │
    │                          │── GET /index.php ──────► │                       │
    │                          │                          │── Load cached hints─► │
    │                          │                          │◄── candidates ─────── │
    │                          │                          │                       │
    │                          │                          │── HTTP 103 ──────────►│
    │◄── 103 Link: hints ─────│◄── 103 Link: hints ─────│                        │
    │                          │                          │                       │
    │                          │                          │── Page render ───────►│
    │                          │                          │                       │
    │                          │                          │   ├── CssViewHelper    │
    │                          │                          │   │   └── compile SCSS │
    │                          │                          │   │   └── minify       │
    │                          │                          │   │   └── write cache  │
    │                          │                          │   │   └── compress .gz/│
    │                          │                          │   │   └── register VH  │
    │                          │                          │   │                      │
    │                          │                          │   ├── JsViewHelper     │
    │                          │                          │   │   └── minify        │
    │                          │                          │   │   └── write cache   │
    │                          │                          │   │   └── register VH   │
    │                          │                          │   │                      │
    │                          │                          │   ├── ResponsiveImage  │
    │                          │                          │   │   └── ImageService  │
    │                          │                          │   │   └── (processed    │
    │                          │                          │   │        via TYPO3    │
    │                          │                          │   │        FAL cache)   │
    │                          │                          │                          │
    │                          │                          │◄── HTML output ────────│
    │                          │                          │                          │
    │                          │                          │── Store Early Hint      │
    │                          │                          │   manifest in cache     │
    │                          │                          │                          │
    │                          │◄── HTML + AssetCollector─│                          │
    │◄── HTML (with CSS/JS ───│                          │                          │
    │    references)           │                          │                          │
    │                          │                          │                          │
    │── GET /hash.css ───────► │                          │                          │
    │                          │── Serve .br if Accept ──►│                          │
    │                          │── Else serve .css ──────►│                          │
    │◄── compiled CSS ────────│                          │                          │
    │                          │                          │                          │

Configuration Reference
=======================

Extension Configuration (``LocalConfiguration.php``)
-----------------------------------------------------

All settings are managed via the TYPO3 Extension Configuration or set
programmatically:

.. code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = [
        'enableScssProcessing'      => true,
        'enableMinification'        => true,
        'enableCompression'         => true,
        'compressionLevel'          => 6,
        'enableBrotli'              => true,
        'criticalThresholdByColPos' => [0 => 2, 1 => 0, 3 => 0],
        'viewportBuckets'           => ['mobile' => 768, 'tablet' => 1024, 'desktop' => PHP_INT_MAX],
        'processingCacheLifetime'   => 0,
    ];

TypoScript Settings (``setup.typoscript``)
-------------------------------------------

.. code-block:: typoscript

    plugin.tx_maispace_assets {
        settings {
            # Output directory for compiled assets
            css {
                outputDir = typo3temp/assets/mai_assets/compiled/
            }

            # Compression (gzip/brotli of compiled files)
            compression {
                enable = 1
                brotli = 1
                gzip   = 1
            }

            # HTML output minification (opt-in, disabled by default)
            htmlMinification {
                enable           = 0
                stripComments    = 1
                preserveTags     = pre,code,textarea
            }
        }
    }

Cache Backend Comparison
==========================

.. list-table::
    :header-rows: 1
    :widths: 20 30 25 25

    * - Cache
      - Backend
      - Advantage
      - Trade-off
    * - Compiled file cache
      - Filesystem (raw PHP I/O)
      - Fastest for file serving via web server
      - No TTL-based expiry (manual cleanup needed for orphaned files)
    * - ``mai_assets_above_fold``
      - ``Typo3DatabaseBackend``
      - Cache tags enable precise invalidation by page ID
      - Slightly slower than file backend for small datasets
    * - ``mai_assets`` (general)
      - ``FileBackend``
      - Persistent across cache flushes (not tagged to ``pages`` groups)
      - Must be flushed manually for full cleanup
    * - ``mai_assets_early_hints``
      - ``Typo3DatabaseBackend``
      - Tag-based flush by ``pageId_*`` aligns with page cache invalidation
      - Slightly slower than file for repeated lookups on every request

Future Considerations
======================

The following topics are identified as areas for future ADRs (Architecture
Decision Records):

1. **Storage Path** — The ``typo3temp/assets/mai_assets/compiled/`` path is a
   convention inherited from TYPO3 core. An ADR should evaluate whether a
   dedicated ``public/``-relative directory would improve CDN integration.

2. **Readiness Gate** — The current warm-up model requires real user visits to
   populate observer data. An ADR could explore synthetic traffic generation or
   predictive warm-up based on content hierarchy.

3. **Early Hints Delivery** — The current implementation emits raw ``header()``
   calls. An ADR should compare ``.htaccess`` redirect patterns, a PHP
   fallback middleware (for non-Apache servers), and a hybrid approach
   combining HTTP 103 with static HTML fallback.

4. **Orphaned File Cleanup** — The content-hash keying strategy produces
   orphaned cache files when source files are edited. An ADR could define a
   TYPO3 CLI command for garbage collection or a TTL-based cleanup mechanism.

5. **CDN Integration** — The compiled file path beneath ``typo3temp/`` may not
   be ideal for CDN origin pulls. An ADR should evaluate relocating the asset
   cache to ``public/assets/`` or similar.
