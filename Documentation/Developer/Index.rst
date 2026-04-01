.. _developer:

=====================
Developer Reference
=====================

Custom Asset Processors
========================

Implement ``Maispace\MaiAssets\Processing\AssetProcessorInterface`` and register your
class as a service. Two methods are required:

.. code-block:: php

    final class MyCustomProcessor implements AssetProcessorInterface
    {
        public function canProcess(string $filePath): bool
        {
            return str_ends_with($filePath, '.less');
        }

        public function process(string $content, string $sourcePath): string
        {
            // Return processed content string
            return $this->compileLess($content, $sourcePath);
        }
    }

To extend the caching and event dispatch from ``AbstractAssetProcessor``, extend it
instead and implement ``doProcess()``:

.. code-block:: php

    final class MyCustomProcessor extends AbstractAssetProcessor
    {
        public function canProcess(string $filePath): bool { return true; }

        protected function doProcess(string $content, string $sourcePath): string
        {
            return $this->transform($content);
        }
    }

Custom Collectors
==================

Extend ``AbstractAssetCollector`` and implement ``build(): string``. Register your
collector as a shared singleton in ``Services.yaml``:

.. code-block:: yaml

    Vendor\MyExt\Collector\MyCollector:
        shared: true

Extending Critical Detection
=============================

Listen to ``ModifyCriticalThresholdEvent`` to override thresholds dynamically.

To add entirely new detection signals (e.g. based on user groups), listen to the event
and adjust the threshold before ``CriticalDetectionService`` returns it.

Viewport Buckets
================

The default viewport buckets and their upper-bound pixel widths are:

.. code-block:: php

    'viewportBuckets' => [
        'mobile'  => 768,
        'tablet'  => 1024,
        'desktop' => PHP_INT_MAX,
    ]

The observer reads the ``viewport_bucket`` cookie. Set this cookie server-side or via
JavaScript based on the user's ``window.innerWidth`` before the page is served for
maximum accuracy.

Observer JS Flow
================

1. The script reads the ``viewport_bucket`` cookie to determine the bucket.
2. It checks ``localStorage`` for a key ``mai_assets_p{pageUid}_{bucket}_ts``. If the
   stored timestamp is >= the server-side ``SERVER_RESET_TIMESTAMP``, the observer
   skips execution (already reported for this reset cycle).
3. ``IntersectionObserver`` is set up on all ``[data-ce-uid]`` elements.
4. On ``window load``, the observer is disconnected and a POST request is sent to
   ``/api/mai-assets/above-fold-report`` with the list of intersecting UIDs.
5. On success, the ``SERVER_RESET_TIMESTAMP`` is written to ``localStorage``.
6. ``requestIdleCallback`` is used when available to avoid impacting FID.

Cache Architecture
==================

The ``mai_assets_above_fold`` cache uses ``Typo3DatabaseBackend`` (stored in
``cf_mai_assets_above_fold`` and ``cf_mai_assets_above_fold_tags`` tables).

Cache keys:

- ``page_{pageUid}_{bucket}`` — array of critical UIDs for a page/bucket combination.
- ``buckets_{pageUid}`` — list of bucket names that have data for this page.
- ``reset_{pageUid}`` — integer reset timestamp.

All entries are tagged with ``mai_assets`` and ``pageId_{pageUid}``, so TYPO3's page
cache flush also invalidates above-fold data for that page.

Disabling the Observer via TypoScript
======================================

You cannot disable the observer via TypoScript directly, but you can cancel injection
by listening to ``BeforeObserverScriptInjectedEvent``:

.. code-block:: php

    // In your event listener
    public function __invoke(BeforeObserverScriptInjectedEvent $event): void
    {
        $event->cancel();
    }

Register the listener in ``Services.yaml``:

.. code-block:: yaml

    Vendor\MyExt\EventListener\DisableObserverListener:
        tags:
            - name: event.listener
              identifier: 'my-ext/disable-observer'
              event: Maispace\MaiAssets\Event\BeforeObserverScriptInjectedEvent
