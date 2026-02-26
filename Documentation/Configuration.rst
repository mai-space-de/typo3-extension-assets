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
    technique. When ``1``, every ``<ma:css>`` call that produces a ``<link>`` tag will use:

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

SVG Sprite Settings
===================

.. confval:: plugin.tx_maispace_assets.svgSprite.symbolIdPrefix
    :type: string
    :Default: ``icon-``

    Prefix prepended to symbol IDs that are auto-derived from the filename.
    Example: ``arrow.svg`` → ``icon-arrow``.

    To use no prefix, set it to an empty string:

    .. code-block:: typoscript

        plugin.tx_maispace_assets.svgSprite.symbolIdPrefix =

.. confval:: plugin.tx_maispace_assets.svgSprite.cache
    :type: boolean
    :Default: 1

    Cache the assembled SVG sprite in the ``maispace_assets`` cache. Set to ``0``
    to rebuild the sprite on every request (useful during development, but not for production).

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
        svgSprite {
            symbolIdPrefix = icon-
            cache = 1
        }
    }
