.. _changelog:

=========
Changelog
=========

Version 1.1.0
=============

*Release date: 2026-05-29*

Features
---------

- Added Apache and Nginx rewrite snippets for pre-compressed static asset delivery
  (``Resources/Private/ServerConfig/apache-precompressed-assets.conf`` and
  ``nginx-precompressed-assets.conf``). Snippets cover content-negotiation (Brotli >
  Gzip > uncompressed), 1-year immutable cache headers, correct MIME types for
  compressed variants, ``Vary: Accept-Encoding``, and DDEV-specific configuration
  guidance. Ported from patterns in ``EXT:staticfilecache`` HtaccessGenerator.

Documentation
--------------

- Added comprehensive static file cache architecture documentation
  (``Documentation/Architecture/Index.rst``) covering the three-layer compiled asset
  cache, pre-compression pipeline, TYPO3 caching framework integration, early hints
  pipeline, cache invalidation strategies, and future ADR considerations.
- Updated ``Documentation/Index.rst`` toctree to include the new Architecture section.
- Expanded ``Documentation/Installation/Index.rst`` web server configuration section
  with complete Apache and Nginx configuration examples, DDEV-specific integration
  notes, and pre-compressed file layout documentation.

Version 1.0.0
=============

*Release date: 2026-04-01*

Initial release of the Mai Assets extension.

Features
--------

- SCSS compilation via scssphp with automatic import path resolution.
- CSS and JS minification via matthiasmullie/minify.
- Gzip and Brotli pre-compression of compiled assets.
- SVG sprite collector — all registered icons merged into a single hidden ``<svg>``
  sprite injected after the ``<body>`` opening tag.
- Font preload collector — ``<link rel="preload">`` tags for WOFF2 (and other
  configured formats) injected into ``<head>``.
- Responsive image ViewHelper with AVIF, WebP, and JPEG variant generation per
  breakpoint.
- Video ViewHelper supporting self-hosted (AV1/HEVC/H264 source order), YouTube, and
  Vimeo with lazy-loading facades.
- Self-optimising above-fold detection via IntersectionObserver — the observer script
  posts visible content element UIDs to ``/api/mai-assets/above-fold-report``.
- ``AboveFoldCacheService`` stores per-page, per-viewport-bucket critical UIDs.
- ``CriticalAssetDataProcessor`` adds ``isCritical``, ``loadingStrategy``,
  ``fetchPriority``, ``decodingStrategy``, and ``cssStrategy`` to every content
  element's Fluid template variables.
- TCA fields ``tx_maiassets_force_critical`` and ``tx_maiassets_is_critical`` on
  ``tt_content`` for editor-level overrides.
- ``ContentElementSaveHook`` invalidates above-fold cache when content elements are
  moved or hidden.
- Five PSR-14 events for extension and customisation.
- Full documentation in RST format.
