.. _introduction:

============
Introduction
============

What Does Mai Assets Do?
========================

Mai Assets is a production-grade TYPO3 asset pipeline that automates and optimises the
delivery of CSS, JavaScript, fonts, images, and SVG icons. It is designed around the
concept of **critical path rendering**: assets required to display the visible viewport
without scrolling are delivered inline or eagerly, while all other assets are deferred.

Key Features
------------

- **SCSS compilation** via scssphp with automatic import path resolution.
- **CSS and JS minification** via matthiasmullie/minify.
- **Gzip and Brotli compression** of processed assets written to ``typo3temp/``.
- **SVG sprite building** from registered icons — a single hidden ``<svg>`` sprite is
  injected after ``<body>`` and individual icons use ``<use href="#id">``.
- **Responsive images** with AVIF, WebP, and JPEG variants per breakpoint.
- **Font preloading** injected into ``<head>`` for WOFF2 (and other configured formats).
- **Video ViewHelper** covering self-hosted (AV1/HEVC/H264), YouTube, and Vimeo with
  lazy-loading facades for non-critical use.
- **Self-optimising above-fold detection** — a small IntersectionObserver script reports
  which content elements are visible at initial render. The server stores these UIDs per
  page/viewport bucket and uses them on subsequent requests to drive the ``isCritical``
  flag delivered through the ``CriticalAssetDataProcessor``.

Architecture Overview
=====================

The extension follows a layered architecture:

.. code-block:: text

    HTTP Request
        │
        ├── AboveFoldReportMiddleware  (POST /api/mai-assets/above-fold-report)
        │       └── AboveFoldCacheService (stores per-page/bucket critical UIDs)
        │
        └── TSFE renders page
                │
                ├── CriticalAssetDataProcessor  (sets isCritical, loadingStrategy, ...)
                │       └── CriticalDetectionService
                │               ├── DB fields (force/override)
                │               ├── AboveFoldCacheService (observer data)
                │               └── Heuristic fallback (position in colPos)
                │
                ├── ViewHelpers render markup
                │       ├── mai:svg.icon      → SvgSpriteCollector
                │       ├── mai:asset.criticalStyle → ScssProcessor + MinificationProcessor
                │       ├── mai:asset.preloadFont   → FontPreloadCollector
                │       ├── mai:image.responsive    → ImageVariantService
                │       └── mai:video.video
                │
                └── contentPostProc-output hooks
                        ├── SvgSpriteInjectionHook  (inject sprite + font preloads)
                        └── AboveFoldObserverHook   (inject IntersectionObserver JS)

Critical / Deferred Concept
============================

Every content element can be in one of two states:

**Critical (above-fold)**
    Assets are rendered inline (``<style>``) or with ``fetchpriority="high"`` and
    ``loading="eager"``. Font preload ``<link>`` tags are injected into ``<head>``.

**Deferred (below-fold)**
    CSS is loaded with ``media="print"`` + ``onload`` swap pattern.
    Images get ``loading="lazy"`` and ``decoding="async"``.
    Fonts are not preloaded.

The Self-Optimising Loop
========================

1. On first visit the heuristic fallback determines criticality from content element
   position in colPos.
2. The ``AboveFoldObserver.js`` script uses ``IntersectionObserver`` to record which
   ``[data-ce-uid]`` elements are in the viewport at load time.
3. After ``window load`` the observer posts the list of visible UIDs to
   ``/api/mai-assets/above-fold-report``.
4. The server stores these per page + viewport bucket.
5. On subsequent requests the cache-backed result replaces the heuristic.
6. When editors move content elements (via DataHandler hook), the cache is invalidated
   and a new reset timestamp is issued, causing the observer to re-report.
