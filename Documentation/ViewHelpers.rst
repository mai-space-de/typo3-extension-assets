.. include:: /Includes.rst.txt

.. _viewhelpers:

===========
ViewHelpers
===========

All ViewHelpers are available under the globally registered namespace ``ma``.
No ``{namespace}`` declaration is needed in your Fluid templates.

.. _viewhelper-css:

ma:css
======

Include a CSS asset from a file or inline Fluid content.

.. code-block:: html

    <!-- From a file -->
    <ma:css src="EXT:my_ext/Resources/Public/Css/app.css" />

    <!-- Inline CSS (auto-identifier from content hash) -->
    <ma:css identifier="hero-styles">
        .hero { background: #e63946; color: #fff; padding: 4rem; }
    </ma:css>

    <!-- Critical CSS inlined in <head> -->
    <ma:css identifier="critical" priority="true" inline="true" minify="true">
        body { margin: 0; font-family: sans-serif; }
        :root { --color-primary: #e63946; }
    </ma:css>

    <!-- Non-critical CSS loaded deferred (media="print" swap) -->
    <ma:css src="EXT:theme/Resources/Public/Css/non-critical.css" deferred="true" />

    <!-- Standard <link> in footer, no deferral -->
    <ma:css src="EXT:theme/Resources/Public/Css/layout.css" deferred="false" />

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 20 10 10 15 45

    * - Argument
      - Type
      - Required
      - Default
      - Description
    * - ``identifier``
      - string
      - No
      - auto (md5)
      - Unique key for TYPO3's AssetCollector. Auto-generated from a hash of the
        source content when omitted. Provide an explicit value to reference this
        asset from multiple templates without duplication.
    * - ``src``
      - string
      - No
      - null
      - EXT: path (e.g. ``EXT:my_ext/Resources/Public/Css/app.css``) or absolute
        path to a CSS file. When provided, inline child node content is ignored.
    * - ``priority``
      - bool
      - No
      - ``false``
      - Place the asset in ``<head>`` when ``true``. ``false`` places it in the footer.
    * - ``minify``
      - bool
      - No
      - null
      - Override the TypoScript ``css.minify`` setting for this single asset.
        ``null`` inherits the global setting.
    * - ``inline``
      - bool
      - No
      - ``false``
      - Render the CSS as an inline ``<style>`` tag instead of a ``<link>`` to
        an external file. Useful for critical above-the-fold CSS.
    * - ``deferred``
      - bool
      - No
      - null
      - Load the CSS non-blocking via ``media="print"`` onload swap. A ``<noscript>``
        fallback is appended automatically. ``null`` inherits ``css.deferred`` from TypoScript.
    * - ``media``
      - string
      - No
      - ``all``
      - The ``media`` attribute for the generated ``<link>`` tag.

.. _viewhelper-js:

ma:js
=====

Include a JavaScript asset from a file or inline Fluid content.

.. code-block:: html

    <!-- External file (deferred by default per TypoScript) -->
    <ma:js src="EXT:theme/Resources/Public/JavaScript/app.js" />

    <!-- ES module -->
    <ma:js src="EXT:theme/Resources/Public/JavaScript/app.js" type="module" />

    <!-- Async (loaded in parallel, executed immediately when ready) -->
    <ma:js src="EXT:theme/Resources/Public/JavaScript/analytics.js" async="true" />

    <!-- Critical JS in <head>, no defer -->
    <ma:js src="EXT:theme/Resources/Public/JavaScript/polyfills.js"
           priority="true" defer="false" />

    <!-- Inline JS -->
    <ma:js identifier="page-init">
        document.addEventListener('DOMContentLoaded', function() {
            document.body.classList.add('js-ready');
        });
    </ma:js>

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 20 10 10 15 45

    * - Argument
      - Type
      - Required
      - Default
      - Description
    * - ``identifier``
      - string
      - No
      - auto (md5)
      - Unique key for TYPO3's AssetCollector.
    * - ``src``
      - string
      - No
      - null
      - EXT: path or absolute path to a JS file.
    * - ``priority``
      - bool
      - No
      - ``false``
      - Place in ``<head>`` when ``true``.
    * - ``minify``
      - bool
      - No
      - null
      - Override the TypoScript ``js.minify`` setting.
    * - ``defer``
      - bool
      - No
      - null
      - Add the ``defer`` attribute. ``null`` inherits ``js.defer`` from TypoScript (default: 1).
    * - ``async``
      - bool
      - No
      - ``false``
      - Add the ``async`` attribute. The script is fetched in parallel and executed
        as soon as available.
    * - ``type``
      - string
      - No
      - null
      - The ``type`` attribute (e.g. ``module``). ES modules imply ``defer`` by the browser.

.. _viewhelper-scss:

ma:scss
=======

Compile SCSS to CSS server-side and include the result as a CSS asset.
No Node.js or build pipeline is required.

.. code-block:: html

    <!-- SCSS from file (cache auto-invalidated on file change) -->
    <ma:scss src="EXT:theme/Resources/Private/Scss/main.scss" />

    <!-- SCSS file with additional @import paths -->
    <ma:scss src="EXT:theme/Resources/Private/Scss/main.scss"
             importPaths="EXT:theme/Resources/Private/Scss/Partials,EXT:base/Resources/Private/Scss" />

    <!-- Inline SCSS (identifier derived from content hash) -->
    <ma:scss identifier="hero-theme">
        $primary: #e63946;
        $spacing: 1.5rem;
        .hero { background: $primary; padding: $spacing; color: white; }
    </ma:scss>

    <!-- Inline SCSS as <style> in <head> (critical styles) -->
    <ma:scss identifier="critical-reset" priority="true" inline="true">
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; }
    </ma:scss>

    <!-- Compiled SCSS loaded deferred -->
    <ma:scss src="EXT:theme/Resources/Private/Scss/non-critical.scss" deferred="true" />

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 20 10 10 15 45

    * - Argument
      - Type
      - Required
      - Default
      - Description
    * - ``identifier``
      - string
      - No
      - auto (md5)
      - Unique key for caching and the AssetCollector.
    * - ``src``
      - string
      - No
      - null
      - EXT: path or absolute path to a ``.scss`` file.
    * - ``priority``
      - bool
      - No
      - ``false``
      - Place compiled CSS in ``<head>`` when ``true``.
    * - ``minify``
      - bool
      - No
      - null
      - Use scssphp's ``OutputStyle::COMPRESSED``. ``null`` inherits ``scss.minify``.
    * - ``inline``
      - bool
      - No
      - ``false``
      - Render compiled CSS as an inline ``<style>`` tag.
    * - ``deferred``
      - bool
      - No
      - null
      - Load compiled CSS non-blocking. ``null`` inherits ``css.deferred``.
    * - ``media``
      - string
      - No
      - ``all``
      - The ``media`` attribute for the generated ``<link>`` tag.
    * - ``importPaths``
      - string
      - No
      - null
      - Comma-separated list of additional import paths. Supports ``EXT:`` notation.

.. _viewhelper-svgsprite:

ma:svgSprite
============

Output an ``<svg><use>`` reference to a symbol from the centrally served SVG sprite.

The sprite is assembled automatically from all ``Configuration/SpriteIcons.php`` files
found across loaded extensions and served from a dedicated HTTP endpoint
(default: ``/maispace/sprite.svg``). The endpoint response is browser-cacheable with
``Cache-Control: public, max-age=31536000, immutable`` and supports conditional requests
via ETag for efficient cache revalidation.

.. seealso::

    See :ref:`configuration-spriteiconregistry` for how to register symbols from your
    own extension and :ref:`events` for the ``BeforeSpriteSymbolRegisteredEvent`` and
    ``AfterSpriteBuiltEvent`` hooks.

Usage
-----

.. code-block:: html

    <!-- Decorative icon (aria-hidden="true" added automatically) -->
    <ma:svgSprite use="icon-arrow" width="24" height="24" class="icon icon--arrow" />

    <!-- Meaningful icon with accessible label (role="img" added automatically) -->
    <ma:svgSprite use="icon-close" aria-label="Close dialog" width="20" height="20" />

    <!-- Icon with <title> for screen reader context -->
    <ma:svgSprite use="icon-external" title="Opens in a new window" class="icon" />

    <!-- Override the sprite URL for a multi-sprite setup -->
    <ma:svgSprite use="icon-logo" src="/brand/sprite.svg" width="120" height="40" />

Generated Output
----------------

For ``<ma:svgSprite use="icon-arrow" width="24" height="24" />``:

.. code-block:: html

    <svg width="24" height="24" aria-hidden="true">
        <use href="/maispace/sprite.svg#icon-arrow"></use>
    </svg>

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 20 10 10 15 45

    * - Argument
      - Type
      - Required
      - Default
      - Description
    * - ``use``
      - string
      - **Yes**
      - —
      - Symbol ID to reference. Must match a key defined in a ``SpriteIcons.php`` file
        (after any ``BeforeSpriteSymbolRegisteredEvent`` renaming).
    * - ``src``
      - string
      - No
      - null
      - Override the sprite URL. When ``null``, the URL is read from the TypoScript
        setting ``plugin.tx_maispace_assets.svgSprite.routePath`` (default:
        ``/maispace/sprite.svg``). Useful for multi-sprite setups.
    * - ``class``
      - string
      - No
      - null
      - CSS class(es) for the ``<svg>`` element.
    * - ``width``
      - string
      - No
      - null
      - ``width`` attribute for the ``<svg>`` element.
    * - ``height``
      - string
      - No
      - null
      - ``height`` attribute for the ``<svg>`` element.
    * - ``aria-hidden``
      - string
      - No
      - ``true``
      - Defaults to ``"true"`` for decorative icons. Set to ``"false"`` together with
        ``aria-label`` to make the icon meaningful to screen readers.
    * - ``aria-label``
      - string
      - No
      - null
      - Accessible label. When set, ``role="img"`` is added automatically and
        ``aria-hidden`` is omitted.
    * - ``title``
      - string
      - No
      - null
      - ``<title>`` element inside the ``<svg>`` for additional screen reader context.

Registering Symbols
-------------------

Symbols are not registered via the ViewHelper. Instead, create
``Configuration/SpriteIcons.php`` in your extension and return an array of symbol
definitions. The registry auto-discovers this file across all loaded extensions:

.. code-block:: php

    <?php
    // EXT:my_sitepackage/Configuration/SpriteIcons.php
    declare(strict_types=1);

    return [
        'icon-arrow' => [
            'src' => 'EXT:my_sitepackage/Resources/Public/Icons/arrow.svg',
        ],
        'icon-close' => [
            'src' => 'EXT:my_sitepackage/Resources/Public/Icons/close.svg',
        ],
        'icon-logo' => [
            'src' => 'EXT:my_sitepackage/Resources/Public/Icons/logo.svg',
        ],
    ];

The symbol array key is the ID used in ``<ma:svgSprite use="icon-arrow" />``.

Complete Layout Example
-----------------------

.. code-block:: html

    <!-- Layout.html — symbols registered in SpriteIcons.php, no register calls needed -->
    <html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers">
    <head>
        <!-- Critical CSS inlined in <head> -->
        <ma:scss identifier="critical" priority="true" inline="true">
            body { margin: 0; font-family: sans-serif; }
        </ma:scss>
    </head>
    <body>
        <header>
            <!-- Use any symbol registered in SpriteIcons.php -->
            <ma:svgSprite use="icon-logo" width="120" height="40" aria-label="Company Logo" />
        </header>

        <f:render section="Content" />

        <!-- Non-critical CSS loaded deferred -->
        <ma:scss src="EXT:theme/Resources/Private/Scss/layout.scss" />

        <!-- JS at end of body, deferred -->
        <ma:js src="EXT:theme/Resources/Public/JavaScript/app.js" />
    </body>
    </html>
