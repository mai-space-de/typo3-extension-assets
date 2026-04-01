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
========================

For Brotli and Gzip pre-compressed files to be served automatically, configure your web
server to prefer pre-compressed variants.

**Nginx:**

.. code-block:: nginx

    gzip_static on;
    # For Brotli (requires ngx_brotli module):
    brotli_static on;

**Apache (.htaccess):**

.. code-block:: apache

    <IfModule mod_rewrite.c>
        RewriteCond %{HTTP:Accept-Encoding} br
        RewriteCond %{REQUEST_FILENAME}.br -f
        RewriteRule ^(.*)$ $1.br [L]
        Header always set Content-Encoding br

        RewriteCond %{HTTP:Accept-Encoding} gzip
        RewriteCond %{REQUEST_FILENAME}.gz -f
        RewriteRule ^(.*)$ $1.gz [L]
        Header always set Content-Encoding gzip
    </IfModule>

The pre-compressed files are written to ``typo3temp/assets/mai_assets/compiled/``.

Content Element Data Attribute
===============================

For the IntersectionObserver to work, your Fluid templates must add a ``data-ce-uid``
attribute to the wrapper of each content element:

.. code-block:: html

    <div data-ce-uid="{data.uid}">
        <!-- content element output -->
    </div>
