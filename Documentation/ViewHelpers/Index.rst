.. _viewhelpers:

===========
ViewHelpers
===========

All ViewHelpers are available under the namespace ``mai``, registered in TypoScript as:

.. code-block:: typoscript

    config.namespaces.mai = Maispace\MaiAssets\ViewHelpers

Or declare in Fluid templates:

.. code-block:: html

    {namespace mai=Maispace\MaiAssets\ViewHelpers}

.. _viewhelper-svg-icon:

mai:svg.icon
============

Renders an inline SVG icon via the sprite system.

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 20 10 10 60

    * - Argument
      - Type
      - Required
      - Description
    * - ``identifier``
      - string
      - Yes
      - Unique symbol ID within the sprite.
    * - ``source``
      - string
      - Yes
      - EXT: path to the SVG source file.
    * - ``label``
      - string
      - No
      - Accessible label. When set, ``role="img"`` and ``aria-label`` are added. Omit for decorative icons.
    * - ``class``
      - string
      - No
      - CSS class applied to the ``<svg>`` element.
    * - ``size``
      - string
      - No (default: ``1em``)
      - Inline ``width`` and ``height`` style.

Example
-------

.. code-block:: html

    <!-- Decorative icon -->
    <mai:svg.icon identifier="arrow-right" source="EXT:my_ext/Resources/Public/Icons/arrow-right.svg" size="1.5em"/>

    <!-- Meaningful icon -->
    <mai:svg.icon identifier="search" source="EXT:my_ext/Resources/Public/Icons/search.svg" label="Search" class="icon--search"/>

.. _viewhelper-asset-criticalstyle:

mai:asset.criticalStyle
=======================

Renders a stylesheet inline (critical) or as a deferred ``<link>`` (non-critical).

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 20 10 10 60

    * - Argument
      - Type
      - Required
      - Description
    * - ``identifier``
      - string
      - Yes
      - Unique identifier for deduplication.
    * - ``source``
      - string
      - No
      - EXT: path to a ``.css`` or ``.scss`` file.
    * - ``isCritical``
      - bool
      - Yes
      - ``true`` inlines the CSS, ``false`` defers loading.
    * - ``media``
      - string
      - No (default: ``all``)
      - Media query for the deferred link.

Example
-------

.. code-block:: html

    <mai:asset.criticalStyle identifier="hero-styles"
        source="EXT:my_ext/Resources/Private/Scss/hero.scss"
        isCritical="{isCritical}"/>

.. _viewhelper-asset-preloadfont:

mai:asset.preloadFont
======================

Registers a font file for ``<link rel="preload">`` injection when critical.

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 20 10 10 60

    * - Argument
      - Type
      - Required
      - Description
    * - ``path``
      - string
      - Yes
      - EXT: path to the font file (e.g. ``.woff2``).
    * - ``isCritical``
      - bool
      - Yes
      - Only registers the preload when ``true``.

Example
-------

.. code-block:: html

    <mai:asset.preloadFont path="EXT:my_ext/Resources/Public/Fonts/MyFont.woff2" isCritical="{isCritical}"/>

.. _viewhelper-image-responsive:

mai:image.responsive
====================

Renders a ``<picture>`` element with AVIF, WebP, and JPEG sources per breakpoint.

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 20 10 10 60

    * - Argument
      - Type
      - Required
      - Description
    * - ``image``
      - FileReference
      - Yes
      - FAL file reference object.
    * - ``breakpoints``
      - array
      - Yes
      - Map of bucket name to pixel width, e.g. ``{mobile: 400, tablet: 800, desktop: 1200}``.
    * - ``sizes``
      - string
      - Yes
      - HTML ``sizes`` attribute value.
    * - ``isCritical``
      - bool
      - No (default: ``false``)
      - Controls ``loading``, ``fetchpriority``, ``decoding``, and AVIF preload.
    * - ``alt``
      - string
      - No
      - Image alt text.
    * - ``class``
      - string
      - No
      - CSS class on the ``<img>`` element.

Example
-------

.. code-block:: html

    <mai:image.responsive image="{file}" breakpoints="{mobile: 400, tablet: 800, desktop: 1200}"
        sizes="(max-width: 768px) 100vw, (max-width: 1024px) 50vw, 1200px"
        isCritical="{isCritical}" alt="{file.properties.alternative}"/>

.. _viewhelper-video:

mai:video.video
===============

Renders self-hosted, YouTube, or Vimeo videos with lazy-loading facades.

Arguments
---------

.. list-table::
    :header-rows: 1
    :widths: 20 10 10 60

    * - Argument
      - Type
      - Required
      - Description
    * - ``file``
      - FileReference
      - No
      - FAL file reference for self-hosted video.
    * - ``youtubeId``
      - string
      - No
      - YouTube video ID.
    * - ``vimeoId``
      - string
      - No
      - Vimeo video ID.
    * - ``poster``
      - FileReference
      - No
      - Poster image FAL reference.
    * - ``isCritical``
      - bool
      - No (default: ``false``)
      - For background videos: eager preload when true.
    * - ``type``
      - string
      - No (default: ``content``)
      - ``background`` or ``content``.
    * - ``title``
      - string
      - No
      - Accessible title.
    * - ``class``
      - string
      - No
      - CSS class.

Example
-------

.. code-block:: html

    <!-- YouTube facade -->
    <mai:video.video youtubeId="dQw4w9WgXcQ" title="Introduction Video" class="hero-video"/>

    <!-- Background video -->
    <mai:video.video file="{bgVideo}" type="background" isCritical="{isCritical}" class="bg-video"/>

    <!-- Self-hosted content video -->
    <mai:video.video file="{contentVideo}" poster="{posterImage}" title="Product Demo"/>
