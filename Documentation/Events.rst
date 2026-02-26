.. include:: /Includes.rst.txt

.. _events:

======
Events
======

Maispace Assets dispatches **PSR-14 events** at key points in the asset processing
pipeline. You can register event listeners in your site package to modify asset output,
add copyright headers, inject dynamic CSS variables, log metrics, and more.

Overview
========

.. list-table::
    :header-rows: 1
    :widths: 40 60

    * - Event Class
      - When It Is Fired
    * - :ref:`event-after-css-processed`
      - After CSS is minified (or read from file), before caching and registration.
    * - :ref:`event-after-js-processed`
      - After JS is minified (or read from file), before caching and registration.
    * - :ref:`event-after-scss-compiled`
      - After SCSS is compiled to CSS by scssphp, before caching and registration.
    * - :ref:`event-after-svg-sprite-built`
      - After the SVG sprite HTML is assembled, before caching and output.

All events carry **mutable data** — call ``set*()`` methods to modify the output.
The modified content is cached, so subsequent requests serve the listener-modified version.

Registering a Listener
=======================

Add a listener in your site package's ``Configuration/Services.yaml``:

.. code-block:: yaml

    # my_site_package/Configuration/Services.yaml

    services:
        _defaults:
            autowire: true
            autoconfigure: true
            public: false

        MyVendor\MySitePackage\:
            resource: '../Classes/*'

        MyVendor\MySitePackage\EventListener\MyCssListener:
            tags:
                -   name: event.listener
                    identifier: 'my-site-css-processor'
                    event: Maispace\MaispaceAssets\Event\AfterCssProcessedEvent

.. _event-after-css-processed:

AfterCssProcessedEvent
======================

**Class:** ``Maispace\MaispaceAssets\Event\AfterCssProcessedEvent``

Fired by ``AssetProcessingService::handleCss()`` after a CSS asset is minified
(if enabled), but before it is written to disk and registered with the AssetCollector.

API
---

.. list-table::
    :header-rows: 1
    :widths: 40 60

    * - Method
      - Description
    * - ``getIdentifier(): string``
      - The asset identifier (auto-generated or from the ``identifier`` argument).
    * - ``getProcessedCss(): string``
      - The processed (and optionally minified) CSS content.
    * - ``setProcessedCss(string $css): void``
      - Replace the CSS that will be cached and registered.
    * - ``getViewHelperArguments(): array``
      - The raw ViewHelper argument array (``identifier``, ``src``, ``priority``,
        ``minify``, ``inline``, ``deferred``, ``media``).
    * - ``isInline(): bool``
      - ``true`` if the asset is rendered as an inline ``<style>`` tag.
    * - ``isPriority(): bool``
      - ``true`` if the asset is placed in ``<head>``.
    * - ``isDeferred(): bool``
      - ``true`` if the asset uses deferred loading.

Example Listener
----------------

.. code-block:: php

    <?php
    // my_site_package/Classes/EventListener/ThemeCssListener.php

    declare(strict_types=1);

    namespace MyVendor\MySitePackage\EventListener;

    use Maispace\MaispaceAssets\Event\AfterCssProcessedEvent;

    final class ThemeCssListener
    {
        public function __construct(
            private readonly ThemeRepository $themeRepository,
        ) {}

        public function __invoke(AfterCssProcessedEvent $event): void
        {
            // Inject CSS custom properties from the database only for priority (head) assets.
            if (!$event->isPriority()) {
                return;
            }

            $theme = $this->themeRepository->findCurrentTheme();
            $vars  = ':root {'
                . '--color-primary: ' . $theme->getPrimaryColor() . ';'
                . '--color-secondary: ' . $theme->getSecondaryColor() . ';'
                . '}';

            $event->setProcessedCss($vars . "\n" . $event->getProcessedCss());
        }
    }

.. code-block:: yaml

    # Services.yaml
    MyVendor\MySitePackage\EventListener\ThemeCssListener:
        tags:
            -   name: event.listener
                identifier: 'my-site-theme-css'
                event: Maispace\MaispaceAssets\Event\AfterCssProcessedEvent

.. _event-after-js-processed:

AfterJsProcessedEvent
=====================

**Class:** ``Maispace\MaispaceAssets\Event\AfterJsProcessedEvent``

Fired by ``AssetProcessingService::handleJs()`` after a JS asset is minified,
before it is written to disk and registered with the AssetCollector.

API
---

.. list-table::
    :header-rows: 1
    :widths: 40 60

    * - Method
      - Description
    * - ``getIdentifier(): string``
      - The asset identifier.
    * - ``getProcessedJs(): string``
      - The processed (and optionally minified) JavaScript content.
    * - ``setProcessedJs(string $js): void``
      - Replace the JS that will be cached and registered.
    * - ``getViewHelperArguments(): array``
      - The raw ViewHelper argument array.
    * - ``isInlineCode(): bool``
      - ``true`` if the JS was written inline in the Fluid template (not from a file).
    * - ``isPriority(): bool``
      - ``true`` if the asset is placed in ``<head>``.
    * - ``isDeferred(): bool``
      - ``true`` if the ``defer`` attribute is set.

Example Listener
----------------

.. code-block:: php

    <?php
    // my_site_package/Classes/EventListener/ConfigInjectorListener.php

    declare(strict_types=1);

    namespace MyVendor\MySitePackage\EventListener;

    use Maispace\MaispaceAssets\Event\AfterJsProcessedEvent;

    final class ConfigInjectorListener
    {
        public function __invoke(AfterJsProcessedEvent $event): void
        {
            // Inject a site configuration object before every inline script.
            if (!$event->isInlineCode()) {
                return;
            }

            $config = json_encode([
                'lang'    => $GLOBALS['TSFE']->sys_language_isocode ?? 'en',
                'baseUrl' => \TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('TYPO3_SITE_URL'),
            ]);

            $event->setProcessedJs(
                'window.__SITE_CONFIG__ = ' . $config . ";\n" . $event->getProcessedJs(),
            );
        }
    }

.. _event-after-scss-compiled:

AfterScssCompiledEvent
======================

**Class:** ``Maispace\MaispaceAssets\Event\AfterScssCompiledEvent``

Fired by ``AssetProcessingService::handleScss()`` after SCSS has been compiled
to CSS by scssphp, before the result is cached.

API
---

.. list-table::
    :header-rows: 1
    :widths: 40 60

    * - Method
      - Description
    * - ``getIdentifier(): string``
      - The asset identifier.
    * - ``getOriginalScss(): string``
      - The raw SCSS source that was passed to the compiler (read-only).
    * - ``getCompiledCss(): string``
      - The compiled CSS output.
    * - ``setCompiledCss(string $css): void``
      - Replace the compiled CSS before caching and registration.
    * - ``getViewHelperArguments(): array``
      - The raw ViewHelper argument array.
    * - ``isInline(): bool``
      - ``true`` if the result will be rendered as an inline ``<style>`` tag.

Example Listener
----------------

.. code-block:: php

    <?php
    // my_site_package/Classes/EventListener/SizeAuditListener.php

    declare(strict_types=1);

    namespace MyVendor\MySitePackage\EventListener;

    use Maispace\MaispaceAssets\Event\AfterScssCompiledEvent;
    use Psr\Log\LoggerInterface;

    final class SizeAuditListener
    {
        public function __construct(
            private readonly LoggerInterface $logger,
        ) {}

        public function __invoke(AfterScssCompiledEvent $event): void
        {
            $inputSize  = strlen($event->getOriginalScss());
            $outputSize = strlen($event->getCompiledCss());
            $ratio      = $inputSize > 0 ? round(($outputSize / $inputSize) * 100) : 0;

            $this->logger->info(
                'SCSS compiled',
                [
                    'identifier'  => $event->getIdentifier(),
                    'input_bytes' => $inputSize,
                    'output_bytes' => $outputSize,
                    'ratio_percent' => $ratio,
                ],
            );
        }
    }

.. _event-after-svg-sprite-built:

AfterSvgSpriteBuiltEvent
========================

**Class:** ``Maispace\MaispaceAssets\Event\AfterSvgSpriteBuiltEvent``

Fired by ``SvgSpriteService::renderSprite()`` after the SVG sprite HTML is assembled
from all registered symbols, before it is cached and output to the template.

API
---

.. list-table::
    :header-rows: 1
    :widths: 40 60

    * - Method
      - Description
    * - ``getSpriteHtml(): string``
      - The assembled SVG sprite HTML string.
    * - ``setSpriteHtml(string $html): void``
      - Replace the sprite HTML before caching.
    * - ``getRegisteredSymbolIds(): array``
      - Array of all registered symbol ID strings in registration order.

Example Listener
----------------

.. code-block:: php

    <?php
    // my_site_package/Classes/EventListener/StaticSymbolsListener.php

    declare(strict_types=1);

    namespace MyVendor\MySitePackage\EventListener;

    use Maispace\MaispaceAssets\Event\AfterSvgSpriteBuiltEvent;

    /**
     * Appends static brand symbols to every page's SVG sprite without requiring
     * a <ma:svgSprite register="..."> call in every template.
     */
    final class StaticSymbolsListener
    {
        public function __invoke(AfterSvgSpriteBuiltEvent $event): void
        {
            $brandSymbol = '<symbol id="icon-brand" viewBox="0 0 200 60">'
                . '<text x="0" y="40" font-size="40">Brand</text>'
                . '</symbol>';

            // Insert the static symbol before the closing </svg> tag.
            $html = str_replace('</svg>', $brandSymbol . '</svg>', $event->getSpriteHtml());
            $event->setSpriteHtml($html);
        }
    }

.. code-block:: yaml

    # Services.yaml
    MyVendor\MySitePackage\EventListener\StaticSymbolsListener:
        tags:
            -   name: event.listener
                identifier: 'my-site-static-svg-symbols'
                event: Maispace\MaispaceAssets\Event\AfterSvgSpriteBuiltEvent

Example Listeners in the Extension
====================================

The extension ships four example listener classes in
``Classes/EventListener/`` that are **commented out** in ``Configuration/Services.yaml``.
They provide a comprehensive reference for all available event API methods:

*  ``AfterCssProcessedEventListener`` — CSS post-processing examples
*  ``AfterJsProcessedEventListener`` — JS configuration injection examples
*  ``AfterScssCompiledEventListener`` — SCSS output post-processing examples
*  ``AfterSvgSpriteBuiltEventListener`` — SVG sprite manipulation examples
