<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\EventListener;

use Maispace\MaispaceAssets\Event\AfterJsProcessedEvent;

/**
 * Example event listener for AfterJsProcessedEvent.
 *
 * This listener demonstrates how to post-process JavaScript assets after they have
 * been minified but before they are written to disk and registered with the AssetCollector.
 *
 * HOW TO ACTIVATE IN YOUR SITE PACKAGE
 * =====================================
 * This listener is intentionally NOT registered by default. To activate it (or a
 * customised version of it), add the following to your site package's
 * Configuration/Services.yaml:
 *
 *   MyVendor\MySitePackage\EventListener\MyJsListener:
 *       tags:
 *           -   name: event.listener
 *               identifier: 'my-site-js-processor'
 *               event: Maispace\MaispaceAssets\Event\AfterJsProcessedEvent
 *
 * AVAILABLE EVENT API
 * ====================
 * $event->getIdentifier()          — the asset identifier string
 * $event->getProcessedJs()         — the (potentially minified) JS string
 * $event->setProcessedJs($js)      — replace the JS before caching/registration
 * $event->getViewHelperArguments() — the raw ViewHelper argument array
 * $event->isInlineCode()           — true when JS was written inline in the template
 * $event->isPriority()             — true when placed in <head>
 * $event->isDeferred()             — true when the defer attribute is set
 *
 * USE CASES
 * ==========
 * - Inject runtime configuration constants (API endpoints, feature flags) into JS
 * - Prepend a strict mode declaration or copyright comment
 * - Log JS asset processing for performance auditing
 * - Apply source map injection or CSP nonce patterns
 *
 * @see AfterJsProcessedEvent
 */
final class AfterJsProcessedEventListener
{
    public function __invoke(AfterJsProcessedEvent $event): void
    {
        // -----------------------------------------------------------------
        // Example A: Inject a JavaScript configuration object before every
        //            inline script (e.g., for passing server-side data to JS).
        //
        // if ($event->isInlineCode()) {
        //     $config = json_encode([
        //         'baseUrl' => \TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('TYPO3_SITE_URL'),
        //         'lang'    => $GLOBALS['TSFE']->sys_language_isocode ?? 'en',
        //     ]);
        //     $event->setProcessedJs(
        //         'window.__SITE_CONFIG__ = ' . $config . ';' . "\n" .
        //         $event->getProcessedJs()
        //     );
        // }
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example B: Prepend 'use strict'; to all JS assets.
        //
        // $event->setProcessedJs("'use strict';\n" . $event->getProcessedJs());
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example C: Log deferred scripts for performance monitoring.
        //
        // if ($event->isDeferred()) {
        //     error_log('[maispace_assets] Deferred JS: ' . $event->getIdentifier());
        // }
        // -----------------------------------------------------------------
    }
}
