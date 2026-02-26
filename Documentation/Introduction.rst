.. include:: /Includes.rst.txt

.. _introduction:

============
Introduction
============

**Maispace Assets** provides a set of Fluid ViewHelpers that make it easy to include CSS,
JavaScript, and SCSS assets directly from Fluid templates — either by writing the code
inline or by referencing an EXT: file path. The extension is performance-first by default:
all assets are minified, cached, and loaded in a non-render-blocking way.

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

SVG Sprite ViewHelper
---------------------

*  Build a **per-request SVG sprite** from any number of individual SVG files.
*  Register SVG symbols from partials, loops, and templates. Duplicate registrations are
   silently ignored.
*  Output the sprite as a single hidden ``<svg>`` block at the top of ``<body>`` to avoid
   repeated inline SVG markup — saving bandwidth and improving caching.
*  Reference symbols anywhere with ``<ma:svgSprite use="icon-name" />``.
*  Fully accessible: decorative icons default to ``aria-hidden="true"``; pass
   ``aria-label`` to make an icon meaningful to screen readers.

Extensibility
-------------

*  **PSR-14 events** are dispatched after each asset is processed, giving listeners the
   opportunity to modify the output (e.g., inject CSS custom properties from a database,
   add copyright headers, or log processing metrics).
*  **Four example event listeners** ship with the extension and document every available
   event API method. They are inactive by default — activate them in your site package.
*  All default behaviours (minify, defer, cache) can be overridden globally via
   **TypoScript** or per-asset via **ViewHelper arguments**.

Global ViewHelper Namespace
===========================

The namespace ``ma`` is registered globally, so no ``{namespace}`` declaration is needed
at the top of your Fluid templates:

.. code-block:: html

    <!-- CSS from a file -->
    <ma:css src="EXT:theme/Resources/Public/Css/app.css" />

    <!-- Inline JS -->
    <ma:js identifier="page-init">
        document.addEventListener('DOMContentLoaded', function() { console.log('ready'); });
    </ma:js>

    <!-- SCSS compiled server-side -->
    <ma:scss src="EXT:theme/Resources/Private/Scss/main.scss" />

    <!-- SVG sprite (in <body>) -->
    <ma:svgSprite register="EXT:theme/Resources/Public/Icons/arrow.svg" />
    <ma:svgSprite render="true" />
    <ma:svgSprite use="icon-arrow" width="24" height="24" class="icon" />
