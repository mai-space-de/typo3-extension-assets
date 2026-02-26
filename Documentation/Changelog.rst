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

*  **CSS ViewHelper** (``ma:css``) — Include CSS from file (EXT: path) or inline Fluid
   content. Supports minification, deferred loading (``media="print"`` swap), inline
   ``<style>`` in ``<head>``, and the ``media`` attribute.

*  **JS ViewHelper** (``ma:js``) — Include JavaScript from file or inline. Supports
   minification, ``defer``, ``async``, ``type="module"``, and footer/head placement.

*  **SCSS ViewHelper** (``ma:scss``) — Compile SCSS to CSS server-side via scssphp
   (pure PHP, no Node.js required). Supports configurable import paths, cache
   auto-invalidation on file change (``filemtime``), and all CSS placement options.

*  **SVG Sprite ViewHelper** (``ma:svgSprite``) — Build a per-request SVG sprite from
   individual SVG files. Three modes: ``register`` (accumulate symbols), ``render``
   (output hidden sprite block), and ``use`` (output ``<use>`` reference). Fully
   accessible with automatic ``aria-hidden`` and ``aria-label`` / ``role="img"`` support.

*  **PSR-14 Events** — Four events dispatched at each processing stage:

   *  ``AfterCssProcessedEvent``
   *  ``AfterJsProcessedEvent``
   *  ``AfterScssCompiledEvent``
   *  ``AfterSvgSpriteBuiltEvent``

   All events expose mutable setters so listeners can modify the final output.

*  **Example Event Listeners** — Four documented example listeners (commented out by
   default) demonstrating CSS variable injection, JS configuration injection, SCSS
   size auditing, and SVG sprite symbol appending.

*  **TYPO3 Caching Framework integration** — Processed assets are cached in the
   ``maispace_assets`` FileBackend cache (grouped with the page cache). SCSS file
   caches are additionally keyed by ``filemtime`` for automatic development-time
   invalidation.

*  **Debug mode** — All processing disabled automatically when a backend user is
   logged in and ``?debug=1`` is present in the URL.

*  **TypoScript configuration** — Full configuration under
   ``plugin.tx_maispace_assets`` with sensible production defaults (minify=1,
   deferred/defer=1 by default).

*  **Global Fluid namespace** — The ``ma`` namespace is registered globally; no
   ``{namespace}`` declaration needed in templates.

Compatibility
-------------

*  TYPO3 12.4 LTS and 13.x LTS
*  PHP 8.1 or later
*  ``matthiasmullie/minify ^1.3``
*  ``scssphp/scssphp ^1.12``
