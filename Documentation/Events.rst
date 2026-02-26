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
    * - :ref:`event-before-sprite-symbol-registered`
      - Before each SVG symbol is added to the registry during auto-discovery. Listeners
        can rename, reconfigure, or veto individual symbols.
    * - :ref:`event-after-sprite-built`
      - After the full SVG sprite XML is assembled, before it is cached and served.
    * - :ref:`event-before-image-processing`
      - Before an image is processed by TYPO3's ImageService. Listeners can modify
        processing instructions, force a target format (e.g. WebP/AVIF), or skip
        processing entirely.
    * - :ref:`event-after-image-processed`
      - After an image has been processed by ImageService. Listeners can inspect the
        result, replace the ProcessedFile, or trigger CDN cache warming.

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

.. _event-before-sprite-symbol-registered:

BeforeSpriteSymbolRegisteredEvent
==================================

**Class:** ``Maispace\MaispaceAssets\Event\BeforeSpriteSymbolRegisteredEvent``

Fired by ``SpriteIconRegistry`` once for each symbol found in any extension's
``Configuration/SpriteIcons.php`` during auto-discovery. Listeners can rename a symbol,
alter its source path, or veto it entirely so it is not included in the sprite.

Auto-discovery runs lazily on the first call to ``buildSprite()`` and is idempotent —
the event fires exactly once per symbol per request.

API
---

.. list-table::
    :header-rows: 1
    :widths: 40 60

    * - Method
      - Description
    * - ``getSymbolId(): string``
      - The current symbol ID (array key from ``SpriteIcons.php``).
    * - ``setSymbolId(string $symbolId): void``
      - Rename the symbol. The new ID is used in the assembled sprite and in
        ``<use href="...#new-id">`` references.
    * - ``getConfig(): array``
      - Current configuration array (at minimum: ``['src' => 'EXT:...']``).
    * - ``setConfig(array $config): void``
      - Replace the entire configuration — useful for redirecting to a different source file.
    * - ``getSourceExtensionKey(): string``
      - The extension key that contributed this symbol (e.g. ``my_sitepackage``).
    * - ``skip(): void``
      - Veto this symbol. It will not be included in the assembled sprite.
    * - ``isSkipped(): bool``
      - Returns ``true`` if ``skip()`` was called by this or a previous listener.

Example Listener
----------------

.. code-block:: php

    <?php
    // my_site_package/Classes/EventListener/SpriteSymbolFilterListener.php

    declare(strict_types=1);

    namespace MyVendor\MySitePackage\EventListener;

    use Maispace\MaispaceAssets\Event\BeforeSpriteSymbolRegisteredEvent;

    /**
     * Rename symbols from a third-party extension and exclude any that are
     * not needed on this project.
     */
    final class SpriteSymbolFilterListener
    {
        /** Symbol IDs from third-party extensions to exclude. */
        private const BLOCKED = ['icon-legacy-close', 'icon-deprecated-arrow'];

        public function __invoke(BeforeSpriteSymbolRegisteredEvent $event): void
        {
            // Exclude unwanted symbols from any extension.
            if (in_array($event->getSymbolId(), self::BLOCKED, true)) {
                $event->skip();
                return;
            }

            // Prefix all symbols contributed by a specific extension.
            if ($event->getSourceExtensionKey() === 'base_theme') {
                $event->setSymbolId('base-' . $event->getSymbolId());
            }
        }
    }

.. code-block:: yaml

    # Services.yaml
    MyVendor\MySitePackage\EventListener\SpriteSymbolFilterListener:
        tags:
            -   name: event.listener
                identifier: 'my-site-sprite-symbol-filter'
                event: Maispace\MaispaceAssets\Event\BeforeSpriteSymbolRegisteredEvent

.. _event-after-sprite-built:

AfterSpriteBuiltEvent
=====================

**Class:** ``Maispace\MaispaceAssets\Event\AfterSpriteBuiltEvent``

Fired by ``SpriteIconRegistry::buildSprite()`` after the full SVG sprite XML is
assembled from all registered symbols, before it is stored in the cache and served
by ``SvgSpriteMiddleware``.

Use this event to append static symbols that are not contributed by any extension,
inject ``<defs>`` blocks, add an XML declaration, log bundle-size metrics, or
minify/prettify the sprite XML.

API
---

.. list-table::
    :header-rows: 1
    :widths: 40 60

    * - Method
      - Description
    * - ``getSpriteXml(): string``
      - The full assembled SVG sprite XML document.
    * - ``setSpriteXml(string $xml): void``
      - Replace the XML before it is cached and served.
    * - ``getRegisteredSymbolIds(): array``
      - Array of all symbol ID strings included in the sprite.

Example Listener
----------------

.. code-block:: php

    <?php
    // my_site_package/Classes/EventListener/StaticBrandSymbolListener.php

    declare(strict_types=1);

    namespace MyVendor\MySitePackage\EventListener;

    use Maispace\MaispaceAssets\Event\AfterSpriteBuiltEvent;

    /**
     * Appends a static brand symbol (defined inline, not from a file) and
     * injects a reusable gradient <defs> block into the assembled sprite.
     */
    final class StaticBrandSymbolListener
    {
        public function __invoke(AfterSpriteBuiltEvent $event): void
        {
            $defs = '<defs>'
                . '<linearGradient id="grad-brand" x1="0" y1="0" x2="1" y2="1">'
                . '<stop offset="0%" stop-color="#e63946"/>'
                . '<stop offset="100%" stop-color="#c1121f"/>'
                . '</linearGradient>'
                . '</defs>';

            $brandSymbol = '<symbol id="icon-brand-logo" viewBox="0 0 200 60">'
                . '<rect width="200" height="60" fill="url(#grad-brand)"/>'
                . '<text x="10" y="42" font-size="32" fill="#fff">Brand</text>'
                . '</symbol>';

            // Insert <defs> + the static symbol before the closing </svg> tag.
            $xml = str_replace(
                '</svg>',
                $defs . $brandSymbol . '</svg>',
                $event->getSpriteXml(),
            );
            $event->setSpriteXml($xml);
        }
    }

.. code-block:: yaml

    # Services.yaml
    MyVendor\MySitePackage\EventListener\StaticBrandSymbolListener:
        tags:
            -   name: event.listener
                identifier: 'my-site-static-brand-symbol'
                event: Maispace\MaispaceAssets\Event\AfterSpriteBuiltEvent

.. _event-before-image-processing:

BeforeImageProcessingEvent
==========================

**Class:** ``Maispace\MaispaceAssets\Event\BeforeImageProcessingEvent``

Fired by ``ImageRenderingService::processImage()`` before an image is submitted to
TYPO3's ``ImageService`` for resizing or format conversion. Listeners can inspect the
source file, modify processing instructions, force a target output format, or skip
processing entirely so the original file is returned unchanged.

The event is dispatched once per unique file+dimensions combination per request.
A request-scoped cache in ``ImageRenderingService`` prevents duplicate dispatches
when the same image appears in multiple ViewHelper invocations.

API
---

.. list-table::
    :header-rows: 1
    :widths: 40 60

    * - Method
      - Description
    * - ``getFile(): File|FileReference``
      - The source image file or file reference being processed.
    * - ``getInstructions(): array``
      - Current processing instructions passed to ImageService (keys: ``width``,
        ``height``, ``fileExtension``, ``crop``, etc.).
    * - ``setInstructions(array $instructions): void``
      - Replace the full set of processing instructions.
    * - ``getTargetFileExtension(): ?string``
      - Convenience: get the requested target format (e.g. ``"webp"``). Returns
        ``null`` when not explicitly set.
    * - ``setTargetFileExtension(string $extension): void``
      - Convenience: set the target output format (e.g. ``"webp"``, ``"avif"``,
        ``"jpg"``). Extension without leading dot.
    * - ``skip(): void``
      - Bypass processing entirely. The original file is returned as-is without
        resizing or format conversion.
    * - ``isSkipped(): bool``
      - Returns ``true`` if ``skip()`` was called.

Example Listener
----------------

.. code-block:: php

    <?php
    // my_site_package/Classes/EventListener/WebPConversionListener.php

    declare(strict_types=1);

    namespace MyVendor\MySitePackage\EventListener;

    use Maispace\MaispaceAssets\Event\BeforeImageProcessingEvent;

    /**
     * Globally force WebP output for all raster images.
     * Equivalent to adding fileExtension="webp" on every <mai:image> — but done once, here.
     */
    final class WebPConversionListener
    {
        private const CONVERTIBLE = ['image/jpeg', 'image/png', 'image/bmp', 'image/tiff'];

        public function __invoke(BeforeImageProcessingEvent $event): void
        {
            // Respect an explicitly configured format (e.g., fileExtension="avif")
            if ($event->getTargetFileExtension() !== null) {
                return;
            }

            if (in_array($event->getFile()->getMimeType(), self::CONVERTIBLE, true)) {
                $event->setTargetFileExtension('webp');
            }
        }
    }

.. code-block:: yaml

    # Services.yaml
    MyVendor\MySitePackage\EventListener\WebPConversionListener:
        tags:
            -   name: event.listener
                identifier: 'my-site-webp-conversion'
                event: Maispace\MaispaceAssets\Event\BeforeImageProcessingEvent

.. _event-after-image-processed:

AfterImageProcessedEvent
========================

**Class:** ``Maispace\MaispaceAssets\Event\AfterImageProcessedEvent``

Fired by ``ImageRenderingService::processImage()`` after TYPO3's ``ImageService`` has
finished processing an image. Listeners can inspect the result (URL, dimensions, format),
replace the ``ProcessedFile`` with one from an external CDN or image API, log processing
metrics, or trigger cache warming.

.. note::

    This event fires after the request-scoped cache has been populated. Replacing the
    ``ProcessedFile`` via ``setProcessedFile()`` does NOT invalidate that cache entry —
    the replacement is returned directly to the caller for this request only.
    To modify instructions before processing, listen to :ref:`event-before-image-processing`.

API
---

.. list-table::
    :header-rows: 1
    :widths: 40 60

    * - Method
      - Description
    * - ``getSourceFile(): File|FileReference``
      - The original source image file or file reference.
    * - ``getProcessedFile(): ProcessedFile``
      - The processed image file returned by ImageService.
    * - ``setProcessedFile(ProcessedFile $processedFile): void``
      - Replace the processed file used for HTML rendering. Useful for substituting
        a CDN-hosted variant.
    * - ``getInstructions(): array``
      - The processing instructions that were applied (read-only). To change
        instructions, listen to ``BeforeImageProcessingEvent`` instead.

Example Listener
----------------

.. code-block:: php

    <?php
    // my_site_package/Classes/EventListener/ImageMetricsListener.php

    declare(strict_types=1);

    namespace MyVendor\MySitePackage\EventListener;

    use Maispace\MaispaceAssets\Event\AfterImageProcessedEvent;
    use Psr\Log\LoggerInterface;

    final class ImageMetricsListener
    {
        public function __construct(
            private readonly LoggerInterface $logger,
        ) {}

        public function __invoke(AfterImageProcessedEvent $event): void
        {
            $processed = $event->getProcessedFile();

            $this->logger->debug('Image processed', [
                'source'    => $event->getSourceFile()->getIdentifier(),
                'output'    => $processed->getIdentifier(),
                'width'     => $processed->getProperty('width'),
                'height'    => $processed->getProperty('height'),
                'mime'      => $processed->getMimeType(),
            ]);
        }
    }

.. code-block:: yaml

    # Services.yaml
    MyVendor\MySitePackage\EventListener\ImageMetricsListener:
        tags:
            -   name: event.listener
                identifier: 'my-site-image-metrics'
                event: Maispace\MaispaceAssets\Event\AfterImageProcessedEvent

Built-in Listener — FontPreloadEventListener
=============================================

``FontPreloadEventListener`` is the only listener that ships **active** (not commented out).
It listens to TYPO3's own ``TYPO3\CMS\Core\Page\Event\BeforeStylesheetsRenderingEvent`` and
calls ``FontRegistry::emitPreloadHeaders()`` to inject ``<link rel="preload" as="font">``
tags just before stylesheets render — ensuring font hints always appear early in ``<head>``.

If you write your own listener for ``BeforeStylesheetsRenderingEvent`` and need it to run
**before** or **after** font preloading, set an explicit ``before``/``after`` relationship
in your ``Services.yaml`` tag:

.. code-block:: yaml

    MyVendor\MySitePackage\EventListener\MyStylesheetListener:
        tags:
            -   name: event.listener
                identifier: 'my-site-stylesheet-listener'
                event: TYPO3\CMS\Core\Page\Event\BeforeStylesheetsRenderingEvent
                # Run after font preloading so fonts are already in <head>:
                after: 'maispace-assets-font-preload'

Example Listeners in the Extension
====================================

The extension ships seven example listener classes in
``Classes/EventListener/`` that are **commented out** in ``Configuration/Services.yaml``.
They provide a comprehensive reference for all available event API methods:

*  ``AfterCssProcessedEventListener`` — CSS post-processing examples
*  ``AfterJsProcessedEventListener`` — JS configuration injection examples
*  ``AfterScssCompiledEventListener`` — SCSS output post-processing examples
*  ``BeforeSpriteSymbolRegisteredEventListener`` — per-symbol filtering and renaming examples
*  ``AfterSpriteBuiltEventListener`` — full sprite post-processing examples
*  ``BeforeImageProcessingEventListener`` — force WebP/AVIF, skip SVG processing, clamp max width
*  ``AfterImageProcessedEventListener`` — log metrics, CDN cache warming, external image API
