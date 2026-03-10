.. include:: /Includes.rst.txt

.. _compression:

===========
Compression
===========

The extension writes Brotli (``.br``) and gzip (``.gz``) pre-compressed variants of every
processed CSS and JS file alongside the plain file in ``typo3temp/``. The SVG sprite
response is compressed at runtime. Both behaviours are controlled by the same
TypoScript settings.

.. contents::
    :local:
    :depth: 2

How it Works
============

**CSS and JS — pre-compressed static files**

Each time a CSS or JS asset is written to ``typo3temp/assets/maispace_assets/``, the
extension also writes compressed siblings:

.. code-block:: text

    typo3temp/assets/maispace_assets/css/
    ├── a3f9d1c2…css          ← plain (always written)
    ├── a3f9d1c2…css.br       ← Brotli, quality 11, BROTLI_TEXT mode
    └── a3f9d1c2…css.gz       ← gzip, level 9

The plain file is always written. The `.br` file requires the PHP ``brotli`` extension
(see :ref:`compression-requirements`); when the extension is absent, the `.br` file is
silently skipped. The `.gz` file uses PHP's built-in ``gzencode()`` (zlib, always
available).

The web server must be configured to detect the pre-compressed variants and serve them
with the appropriate ``Content-Encoding`` header. See the per-server configuration
snippets below.

**SVG sprite — runtime response compression**

The SVG sprite is served by ``SvgSpriteMiddleware``, a PHP PSR-15 middleware, so
compression can be applied directly to the HTTP response body:

1. If the client sends ``Accept-Encoding: br`` and ``compression.brotli = 1`` and the PHP
   ``brotli`` extension is available → the response body is Brotli-compressed and
   ``Content-Encoding: br`` is set.
2. If Brotli is not available or the client does not accept ``br``, and the client sends
   ``Accept-Encoding: gzip`` and ``compression.gzip = 1`` → the response body is
   gzip-compressed and ``Content-Encoding: gzip`` is set.
3. Otherwise → the plain SVG is sent (unchanged behaviour).

The ``ETag`` is computed from the uncompressed SVG content so conditional GET (304)
semantics are unaffected regardless of encoding.

.. _compression-requirements:

PHP Requirements
================

+------------+-----------------------------------------+-----------------------------------------+
| Algorithm  | PHP requirement                         | Notes                                   |
+============+=========================================+=========================================+
| Brotli     | ``brotli`` PECL extension               | ``pecl install brotli``                 |
|            | ``function_exists('brotli_compress')``  | Packages: ``php-brotli``, ``php8x-brotli`` |
+------------+-----------------------------------------+-----------------------------------------+
| gzip       | ``zlib`` extension (built-in)           | Available in all standard PHP builds.   |
|            | ``function_exists('gzencode')``         |                                         |
+------------+-----------------------------------------+-----------------------------------------+

When the ``brotli`` extension is not installed, the extension silently skips writing
``.br`` files and falls back to gzip-only output. No configuration change is needed —
the TypoScript default ``compression.brotli = 1`` is safe to leave enabled on hosts
without the extension.

TypoScript Settings
===================

All three settings are under ``plugin.tx_maispace_assets.compression``.

.. confval:: plugin.tx_maispace_assets.compression.enable
    :type: boolean
    :Default: 1

    Master switch for all compression. Set to ``0`` to disable both pre-compressed
    static file generation and runtime sprite compression entirely.

.. confval:: plugin.tx_maispace_assets.compression.brotli
    :type: boolean
    :Default: 1

    Enable Brotli compression. Requires the PHP ``brotli`` extension. When the
    extension is absent, this setting has no effect (Brotli is silently skipped).

.. confval:: plugin.tx_maispace_assets.compression.gzip
    :type: boolean
    :Default: 1

    Enable gzip compression. Uses PHP's built-in ``gzencode()`` (zlib). Applied as
    fallback for clients without Brotli support, and for pre-compressed ``.gz`` file
    generation.

To disable compression entirely:

.. code-block:: typoscript

    plugin.tx_maispace_assets.compression.enable = 0

To use gzip only (skip Brotli even if the extension is available):

.. code-block:: typoscript

    plugin.tx_maispace_assets.compression {
        brotli = 0
        gzip   = 1
    }

.. _compression-webserver:

Web Server Configuration
========================

The following snippets configure each web server to detect and serve the pre-compressed
``.br`` / ``.gz`` variants of CSS and JS files. Place them in your virtual host
configuration (not in a per-directory ``.htaccess`` where possible).

.. _compression-nginx:

Nginx
-----

Requires the `ngx_brotli <https://github.com/google/ngx_brotli>`__ module for
``brotli_static``. The ``gzip_static`` directive is part of the standard
``ngx_http_gzip_static_module`` (included in most distributions).

.. code-block:: nginx

    # nginx.conf / site configuration
    #
    # Add inside your server {} block.
    # Serves pre-compressed .br / .gz files for maispace/assets output.

    location ~* ^/typo3temp/assets/maispace_assets/ {
        # Serve pre-compressed Brotli variant when the client accepts br.
        # Requires ngx_brotli: https://github.com/google/ngx_brotli
        brotli_static on;

        # Serve pre-compressed gzip variant when the client accepts gzip.
        # Included in standard Nginx (ngx_http_gzip_static_module).
        gzip_static on;

        # Ensure proxy caches store separate variants per encoding.
        add_header Vary Accept-Encoding always;

        # Long-lived cache (assets use content-hash file names).
        expires max;
        access_log off;
    }

To verify ``ngx_brotli`` is available:

.. code-block:: bash

    nginx -V 2>&1 | grep -o 'brotli'

If ``brotli_static`` is not available, omit that line — ``gzip_static`` alone is
sufficient as a fallback.

.. _compression-apache:

Apache
------

Requires ``mod_rewrite`` and ``mod_headers``. Place the ``<Directory>`` block in your
virtual host configuration (``httpd.conf`` / ``<VirtualHost>``), or copy the
``RewriteRule`` and ``<FilesMatch>`` blocks into a ``.htaccess`` file inside
``typo3temp/assets/maispace_assets/``.

.. code-block:: apache

    # Apache virtual host configuration
    #
    # Serves pre-compressed .br / .gz files for maispace/assets output.
    # Requires: mod_rewrite, mod_headers.

    <Directory "/var/www/html/typo3temp/assets/maispace_assets">
        Options -Indexes
        AllowOverride None

        <IfModule mod_rewrite.c>
            RewriteEngine On

            # --- Brotli ---
            # Serve the .br variant when the client accepts br and the file exists.
            RewriteCond %{HTTP:Accept-Encoding} \bbr\b
            RewriteCond %{REQUEST_FILENAME}\.br  -s
            RewriteRule ^(.+)$                   $1.br [L]

            # --- gzip fallback ---
            # Serve the .gz variant when the client accepts gzip and the file exists.
            RewriteCond %{HTTP:Accept-Encoding} \bgzip\b
            RewriteCond %{REQUEST_FILENAME}\.gz  -s
            RewriteRule ^(.+)$                   $1.gz [L]
        </IfModule>

        # Restore the correct Content-Type and set Content-Encoding for .br files.
        <FilesMatch "\.css\.br$">
            <IfModule mod_headers.c>
                ForceType         text/css
                Header set        Content-Encoding br
                Header append     Vary             Accept-Encoding
            </IfModule>
        </FilesMatch>
        <FilesMatch "\.js\.br$">
            <IfModule mod_headers.c>
                ForceType         application/javascript
                Header set        Content-Encoding br
                Header append     Vary             Accept-Encoding
            </IfModule>
        </FilesMatch>

        # Restore the correct Content-Type and set Content-Encoding for .gz files.
        <FilesMatch "\.css\.gz$">
            <IfModule mod_headers.c>
                ForceType         text/css
                Header set        Content-Encoding gzip
                Header append     Vary             Accept-Encoding
            </IfModule>
        </FilesMatch>
        <FilesMatch "\.js\.gz$">
            <IfModule mod_headers.c>
                ForceType         application/javascript
                Header set        Content-Encoding gzip
                Header append     Vary             Accept-Encoding
            </IfModule>
        </FilesMatch>
    </Directory>

.. note::

    The ``ForceType`` directives are required because Apache infers the MIME type from
    the rewritten file name (e.g. ``.css.br``), which it does not recognise. Without
    ``ForceType``, the browser receives the wrong ``Content-Type`` and refuses to apply
    the stylesheet or execute the script.

.. _compression-caddy:

Caddy
-----

Caddy's built-in ``file_server`` natively supports pre-compressed file serving via
the ``precompressed`` option — no third-party module needed.

.. code-block:: caddy

    # Caddyfile
    #
    # Add inside your site block.
    # Serves pre-compressed .br / .gz files for maispace/assets output.

    handle /typo3temp/assets/maispace_assets/* {
        file_server {
            # Check for .br first (Brotli), then .gz (gzip).
            # Caddy sets Content-Encoding and Vary automatically.
            precompressed br gzip
        }
    }

Caddy selects the best available encoding based on the client's ``Accept-Encoding``
header, sets ``Content-Encoding`` and appends ``Vary: Accept-Encoding`` automatically.
No additional header directives are required.

Disabling Compression
=====================

To disable compression for specific environments (e.g. a local development setup where
compressed files are not needed):

.. code-block:: typoscript

    plugin.tx_maispace_assets.compression.enable = 0

When disabled:

* No ``.br`` or ``.gz`` files are written to ``typo3temp/``.
* The SVG sprite is returned uncompressed.
* Web server configuration for compressed variants has no effect (it simply never finds
  the ``.br`` / ``.gz`` files to serve).

Existing compressed files in ``typo3temp/`` are **not** deleted when you disable the
setting — clear ``typo3temp/assets/maispace_assets/`` manually or flush the TYPO3 cache
to remove them.
