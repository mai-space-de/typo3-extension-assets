.. _configuration:

=============
Configuration
=============

Extension Configuration
========================

All settings are managed via the TYPO3 Extension Configuration (Admin Tools > Settings >
Extension Configuration > mai_assets). They can also be set programmatically in
``LocalConfiguration.php`` or ``AdditionalConfiguration.php``:

.. code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = [
        'enableScssProcessing'       => true,
        'enableMinification'         => true,
        'enableCompression'          => true,
        'compressionLevel'           => 6,
        'enableBrotli'               => true,
        'criticalThresholdByColPos'  => [0 => 2, 1 => 0, 3 => 0],
        'viewportBuckets'            => ['mobile' => 768, 'tablet' => 1024, 'desktop' => PHP_INT_MAX],
        'svgStripAttributes'         => ['id', 'class', 'style'],
        'fontPreloadFormats'         => ['woff2'],
        'observerRootMargin'         => '200px',
        'processingCacheLifetime'    => 0,
    ];

Settings Reference
==================

.. list-table::
    :header-rows: 1
    :widths: 30 10 60

    * - Key
      - Default
      - Description
    * - ``enableScssProcessing``
      - ``true``
      - Compile ``.scss`` files to CSS using scssphp.
    * - ``enableMinification``
      - ``true``
      - Minify CSS and JS output using matthiasmullie/minify.
    * - ``enableCompression``
      - ``true``
      - Write ``.gz`` (and optionally ``.br``) pre-compressed variants alongside compiled assets.
    * - ``compressionLevel``
      - ``6``
      - Compression level for gzip and Brotli (1–9). Higher = smaller but slower.
    * - ``enableBrotli``
      - ``true``
      - Write ``.br`` files when ``ext-brotli`` is available.
    * - ``criticalThresholdByColPos``
      - ``[0 => 2, 1 => 0, 3 => 0]``
      - Fallback heuristic: how many content elements in a given colPos are considered critical. ``0`` disables the heuristic for that column.
    * - ``viewportBuckets``
      - ``['mobile'=>768, 'tablet'=>1024, 'desktop'=>PHP_INT_MAX]``
      - Viewport width thresholds. The observer cookie is mapped to the first matching bucket.
    * - ``svgStripAttributes``
      - ``['id','class','style']``
      - Attributes removed from SVG root elements when building the sprite.
    * - ``fontPreloadFormats``
      - ``['woff2']``
      - Font file extensions for which preload links are generated.
    * - ``observerRootMargin``
      - ``'200px'``
      - ``rootMargin`` value passed to the IntersectionObserver for early detection.
    * - ``processingCacheLifetime``
      - ``0``
      - Lifetime in seconds for the compiled asset file cache. ``0`` means infinite (until file hash changes).

TypoScript Constants
=====================

.. code-block:: typoscript

    plugin.tx_maiassets {
        settings {
            enableScssProcessing = 1
            enableMinification = 1
            enableCompression = 1
            enableBrotli = 1
            observerRootMargin = 200px
        }
    }

TypoScript Setup
================

.. code-block:: typoscript

    config.namespaces.mai = Maispace\MaiAssets\ViewHelpers

    tt_content.stdWrap.dataProcessing {
        100 = Maispace\MaiAssets\DataProcessing\CriticalAssetDataProcessor
    }

The ``CriticalAssetDataProcessor`` adds the following variables to every content element's
Fluid template:

.. list-table::
    :header-rows: 1
    :widths: 25 15 60

    * - Variable
      - Type
      - Description
    * - ``isCritical``
      - bool
      - Whether this element is above-fold.
    * - ``loadingStrategy``
      - string
      - ``'eager'`` or ``'lazy'``
    * - ``fetchPriority``
      - string
      - ``'high'`` or ``'low'``
    * - ``decodingStrategy``
      - string
      - ``'sync'`` or ``'async'``
    * - ``cssStrategy``
      - string
      - ``'inline'`` or ``'deferred'``

Example: Disable Observer for a Specific Page
==============================================

You can prevent the observer script from being injected on pages that should always be
treated as fully critical (e.g. landing pages) by adding a custom hook listener that
listens to ``BeforeObserverScriptInjectedEvent`` and calls ``cancel()``.
