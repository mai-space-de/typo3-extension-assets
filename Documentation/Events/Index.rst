.. _events:

======
Events
======

Mai Assets dispatches PSR-14 events at key extension points. Register listeners in your
extension's ``Services.yaml``.

.. _event-afterspritebuiltevent:

AfterSpriteBuiltEvent
=====================

**Class:** ``Maispace\MaiAssets\Event\AfterSpriteBuiltEvent``

Dispatched after the SVG sprite string has been assembled but before it is injected into
the page. Allows listeners to modify or replace the sprite HTML.

Methods
-------

.. list-table::
    :header-rows: 1
    :widths: 30 70

    * - Method
      - Description
    * - ``getSprite(): string``
      - Returns the current sprite HTML string.
    * - ``setSprite(string $sprite): void``
      - Replaces the sprite HTML.

Example Listener
----------------

.. code-block:: php

    final class AddCustomSymbolListener
    {
        public function __invoke(AfterSpriteBuiltEvent $event): void
        {
            $sprite = $event->getSprite();
            // Inject additional symbol before closing </svg>
            $sprite = str_replace('</svg>', '<symbol id="custom">...</symbol></svg>', $sprite);
            $event->setSprite($sprite);
        }
    }

.. _event-aftercriticaluidsupdatedevent:

AfterCriticalUidsUpdatedEvent
==============================

**Class:** ``Maispace\MaiAssets\Event\AfterCriticalUidsUpdatedEvent``

Dispatched after ``AboveFoldCacheService::updateCriticalUids()`` stores a changed set of
critical UIDs. Read-only event — for observability and cache-warming purposes.

Methods
-------

.. list-table::
    :header-rows: 1
    :widths: 30 70

    * - Method
      - Description
    * - ``getPageUid(): int``
      - The page UID for which UIDs were updated.
    * - ``getBucket(): string``
      - The viewport bucket (e.g. ``'desktop'``).
    * - ``getPreviousUids(): array``
      - UIDs before the update.
    * - ``getNewUids(): array``
      - UIDs after the update.

.. _event-beforeassetinjectionevent:

BeforeAssetInjectionEvent
=========================

**Class:** ``Maispace\MaiAssets\Event\BeforeAssetInjectionEvent``

Dispatched by ``AbstractAssetProcessor`` after processing but before returning the
content. Allows listeners to post-process compiled CSS or JS.

Methods
-------

.. list-table::
    :header-rows: 1
    :widths: 30 70

    * - Method
      - Description
    * - ``getContent(): string``
      - The processed asset content.
    * - ``setContent(string $content): void``
      - Replace the content.
    * - ``getType(): string``
      - ``'css'`` or ``'js'``
    * - ``getSource(): string``
      - Absolute path to the source file.

.. _event-beforeobserverscriptinjectedevent:

BeforeObserverScriptInjectedEvent
==================================

**Class:** ``Maispace\MaiAssets\Event\BeforeObserverScriptInjectedEvent``

Dispatched by ``AboveFoldObserverHook`` before the observer ``<script>`` tag is appended
to the page. Allows script replacement or cancellation.

Methods
-------

.. list-table::
    :header-rows: 1
    :widths: 30 70

    * - Method
      - Description
    * - ``getScript(): string``
      - The full ``<script>…</script>`` block.
    * - ``setScript(string $script): void``
      - Replace the script block.
    * - ``cancel(): void``
      - Prevent the script from being injected.
    * - ``isCancelled(): bool``
      - Whether the injection has been cancelled.

Example Listener
----------------

.. code-block:: php

    final class DisableObserverOnLandingPageListener
    {
        public function __invoke(BeforeObserverScriptInjectedEvent $event): void
        {
            // Cancel observer on pages that are always fully critical
            $event->cancel();
        }
    }

.. _event-modifycriticalthresholdevent:

ModifyCriticalThresholdEvent
============================

**Class:** ``Maispace\MaiAssets\Event\ModifyCriticalThresholdEvent``

Dispatched by ``CriticalDetectionService::getThresholdForColPos()``. Allows dynamic
threshold overrides (e.g. based on page type or A/B test groups).

Methods
-------

.. list-table::
    :header-rows: 1
    :widths: 30 70

    * - Method
      - Description
    * - ``getThreshold(): int``
      - Current threshold value.
    * - ``setThreshold(int $threshold): void``
      - Override the threshold.
    * - ``getColPos(): int``
      - The column position being queried.
    * - ``getPageUid(): int``
      - The current page UID.

Example Listener
----------------

.. code-block:: php

    final class ThresholdByPageTypeListener
    {
        public function __invoke(ModifyCriticalThresholdEvent $event): void
        {
            // Treat all elements in colPos 0 as critical on the home page
            if ($event->getColPos() === 0 && $event->getPageUid() === 1) {
                $event->setThreshold(PHP_INT_MAX);
            }
        }
    }
