<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\Service\PageOptimizationReadinessService;
use SFC\Staticfilecache\Event\CacheRuleEvent;

/**
 * Prevents static HTML file writes until a page has accumulated observer data
 * for all configured viewport buckets AND an early-hints manifest exists.
 *
 * During the warm-up phase (no bucket data / no manifest), the page is served
 * dynamically so each real visitor contributes observer data. Once the page is
 * fully "optimisation-ready", subsequent requests are statically cached.
 */
final class StaticFileCacheReadinessListener
{
    public function __construct(
        private readonly PageOptimizationReadinessService $readinessService,
        private readonly EarlyHintCacheService $earlyHintCacheService,
    ) {}

    public function __invoke(CacheRuleEvent $event): void
    {
        $pageInformation = $event->getRequest()->getAttribute('frontend.page.information');
        if (!is_object($pageInformation)) {
            return;
        }

        $pageUid = (int)($pageInformation->getPageRecord()['uid'] ?? 0);
        if ($pageUid <= 0) {
            return;
        }

        if (!$this->readinessService->isReady($pageUid)) {
            $event->addExplanation(__CLASS__, 'Page not fully optimisation-ready (missing viewport bucket data)');
            $event->setSkipProcessing(true);
            return;
        }

        $language = $event->getRequest()->getAttribute('language');
        $languageUid = $language !== null ? (int)$language->getLanguageId() : 0;

        $candidates = $this->earlyHintCacheService->load($pageUid, $languageUid);
        if ($candidates === []) {
            $event->addExplanation(__CLASS__, 'No early-hints manifest for this page/language combination');
            $event->setSkipProcessing(true);
        }
    }
}
