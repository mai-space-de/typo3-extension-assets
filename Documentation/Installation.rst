.. include:: /Includes.rst.txt

.. _installation:

============
Installation
============

Requirements
============

*  PHP 8.1 or later
*  TYPO3 CMS 12.4 LTS or 13.x LTS
*  Composer (recommended)

Composer Installation
=====================

Run the following command in your TYPO3 project root:

.. code-block:: bash

    composer require maispace/assets

This will install the extension together with its PHP dependencies:

*  ``matthiasmullie/minify`` — CSS and JS minification
*  ``scssphp/scssphp`` — Server-side SCSS compilation

Activate the Extension
======================

The extension is activated automatically when installed via Composer. If you manage
extensions via the TYPO3 backend, activate ``maispace_assets`` in the **Extension Manager**.

Include TypoScript
==================

Include the TypoScript setup in your site's TypoScript template. The recommended way
is via the static template:

**Option A: Via TypoScript include (recommended)**

Add the following to your root TypoScript template's **Include static (from extensions)**
field, or include it manually:

.. code-block:: typoscript

    @import 'EXT:maispace_assets/Configuration/TypoScript/setup.typoscript'

**Option B: Via sys_template include path**

In the **sys_template** record for your site, set the include to:

.. code-block:: text

    EXT:maispace_assets/Configuration/TypoScript/setup.typoscript

ViewHelper Namespace
====================

The ViewHelper namespace ``ma`` is registered globally in ``ext_localconf.php``. You
do **not** need to add a ``{namespace}`` declaration to your Fluid templates.

You can use the ViewHelpers immediately:

.. code-block:: html

    <mai:css src="EXT:my_ext/Resources/Public/Css/app.css" />
    <mai:js src="EXT:my_ext/Resources/Public/JavaScript/app.js" />
    <mai:scss src="EXT:my_ext/Resources/Private/Scss/main.scss" />

Output Directory
================

Processed assets are written to ``typo3temp/assets/maispace_assets/`` in your TYPO3
public root. This directory is created automatically. Ensure your web server has write
access to ``typo3temp/``.

The directory can be customised via TypoScript:

.. code-block:: typoscript

    plugin.tx_maispace_assets {
        css.outputDir = typo3temp/assets/maispace_assets/css/
        js.outputDir  = typo3temp/assets/maispace_assets/js/
    }
