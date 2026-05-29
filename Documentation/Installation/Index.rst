.. _installation:

============
Installation
============

Requirements
============

- TYPO3 12.4 or 13.x
- PHP 8.2 or higher
- Composer-based TYPO3 installation

Composer Install
================

.. code-block:: bash

    composer require maispace/mai-assets

This will pull in the required dependencies:

- ``scssphp/scssphp ^1.12``
- ``matthiasmullie/minify ^1.3``

Optional: for Brotli compression support install the PHP ``ext-brotli`` extension:

.. code-block:: bash

    # Debian / Ubuntu
    sudo apt-get install php-brotli

Activate the Extension
=======================

In a composer-based installation the extension is activated automatically. If you need
to activate it manually via the Extension Manager, install ``mai_assets`` from the list.

TypoScript
==========

Include the static TypoScript template **"Mai Assets"** in your site's TypoScript template,
or add the following to your ``setup.typoscript`` and ``constants.typoscript``:

.. code-block:: typoscript

    @import 'EXT:mai_assets/Configuration/TypoScript/constants.typoscript'
    @import 'EXT:mai_assets/Configuration/TypoScript/setup.typoscript'

Database Compare
================

After installation, run a database schema update via the Install Tool or the TYPO3 CLI:

.. code-block:: bash

    vendor/bin/typo3 database:updateschema

This creates the ``mai_assets_above_fold`` cache table and adds the
``tx_maiassets_is_critical`` and ``tx_maiassets_force_critical`` columns to ``tt_content``.

Web Server Configuration
=======================

The ``CompressionProcessor`` (``Classes/Processing/CompressionProcessor.php``) writes
pre-compressed Gzip (``.gz``) and Brotli (``.br``) variants of compiled assets alongside
the uncompressed file. Your web server must be configured to:

1. **Serve pre-compressed variants** — deliver ``.br`` or ``.gz`` when the client
   advertises support in ``Accept-Encoding``.
2. **Set long cache lifetimes** — compiled asset filenames are content-hash based
   (see :ref:`static-file-cache-architecture`), making them immutable once deployed.
   A 1-year cache with ``immutable`` is safe and optimal.
3. **Set correct Content-Type and Content-Encoding headers** — compressed variants
   lose their original extension; the server must announce the correct MIME type and
   encoding.
4. **Add Vary: Accept-Encoding** — so proxies and CDNs cache separate copies for
   clients with different compression support.

Full example config files are available in the extension:

- ``EXT:mai_assets/Resources/Private/ServerConfig/apache-precompressed-assets.conf``
- ``EXT:mai_assets/Resources/Private/ServerConfig/nginx-precompressed-assets.conf``

These files are ported from the patterns found in ``EXT:staticfilecache``'s
``HtaccessGenerator`` and ``Nginx.rst`` documentation, adapted for the asset-pipeline
use case (content-hash immutable files, no HTML page caching, Brotli + Gzip priority).

Apache
------

Place the following directives in your ``public/.htaccess`` **before** the TYPO3 main
rewrite rules (before the ``RewriteRule ^(?:fileadmin/|...`` line), or better, in the
Apache VirtualHost configuration:

.. literalinclude:: /Resources/Private/ServerConfig/apache-precompressed-assets.conf
    :language: apache
    :caption: EXT:mai_assets/Resources/Private/ServerConfig/apache-precompressed-assets.conf
    :linenos:

**How it works:**

1. Mod_rewrite checks ``Accept-Encoding``: if the client supports Brotli and a
   ``.br`` file exists, it serves the Brotli variant. Gzip is the fallback.
2. ``mod_headers`` sets the correct ``Content-Encoding`` header (``br`` or ``gzip``)
   and appends ``Vary: Accept-Encoding``.
3. ``mod_mime`` ensures ``.css.gz`` / ``.css.br`` are served with ``text/css`` (not
   ``application/octet-stream``).
4. ``mod_expires`` + ``mod_headers`` set a 1-year ``Cache-Control: public, immutable``
   on the compiled assets directory (``typo3temp/assets/mai_assets/compiled/``) and on
   ``/_assets/`` for versioned extension assets.

**DDEV-specific notes:**

- DDEV Apache uses ``AllowOverride All`` for ``public/``, so the ``.htaccess`` file is
  honoured.
- If you customise ``.ddev/apache/apache-site.conf``, add the directives directly into
  the ``<VirtualHost>`` block for better performance (``.htaccess`` is re-read on every
  request).

Nginx
-----

Add the following location blocks to your nginx server block — preferably placed **before**
the general ``location /`` block. Pre-compressed serving uses ``gzip_static`` and
``brotli_static`` directives, which check for ``.gz`` / ``.br`` variants transparently
without explicit rewrite rules.

.. literalinclude:: /Resources/Private/ServerConfig/nginx-precompressed-assets.conf
    :language: nginx
    :caption: EXT:mai_assets/Resources/Private/ServerConfig/nginx-precompressed-assets.conf
    :linenos:

**How it works:**

1. ``gzip_static on`` and ``brotli_static on`` — nginx automatically checks for
   ``.gz`` and ``.br`` variants before serving the uncompressed file. The best
   match is chosen based on the client's ``Accept-Encoding`` header.
2. Per-location caching directives set ``expires 1y`` and ``Cache-Control: public,
   immutable`` for the compiled assets directory, with ``Vary: Accept-Encoding``.
3. Security sub-locations restrict serving to known file extensions, preventing
   directory traversal or unintended file disclosure.
4. ``etag off`` and ``access_log off`` reduce overhead for high-volume static
   asset requests.

**DDEV-specific notes:**

- DDEV uses Nginx by default when ``webserver_type: nginx-full`` is set in
  ``.ddev/config.yaml``. The main config is at ``.ddev/nginx_full/nginx-site.conf``.
- To add this configuration without editing the generated file, create a custom
  config file at ``.ddev/nginx/precompressed-assets.conf`` — it is included
  automatically via the ``include /mnt/ddev_config/nginx/*.conf`` directive.
- If you edit the main ``.ddev/nginx_full/nginx-site.conf``, remove the
  ``#ddev-generated`` line so DDEV preserves your changes.

Pre-compressed File Layout
--------------------------

After compilation and compression, the cache directory contains three files per asset:

::

    typo3temp/assets/mai_assets/compiled/
        ├── <md5hash>.css          # Uncompiled / compiled CSS
        ├── <md5hash>.css.gz       # Gzip-compressed (level 6)
        └── <md5hash>.css.br       # Brotli-compressed (level 6, if enabled)

The web server serves the most efficient variant the client supports, with the
uncompressed file as the fallback for clients that do not advertise compression
support.

Compression is configured via ``ExtensionConfiguration``:

.. code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = [
        'enableCompression' => true,   // Master switch
        'compressionLevel'  => 6,      // 1–9 (higher = smaller but slower)
        'enableBrotli'      => true,   // Requires ext-brotli
    ];

Content Element Data Attribute
===============================

For the IntersectionObserver to work, your Fluid templates must add a ``data-ce-uid``
attribute to the wrapper of each content element:

.. code-block:: html

    <div data-ce-uid="{data.uid}">
        <!-- content element output -->
    </div>
