<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\Service\PageOptimizationReadinessService;
use Maispace\MaiAssets\StaticFileCache\StaticHtmlWriterService;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;

/**
 * Persists fully rendered, cacheable frontend HTML to the static file cache
 * after all content transformers (SVG sprite, minification, early hints) have run.
 *
 * Writes are skipped when:
 * - static file cache is disabled in extension configuration;
 * - TYPO3 marks the response as non-cacheable (INT / no_cache);
 * - the request is not a parameter-free GET;
 * - the page is not optimisation-ready (missing viewport bucket data);
 * - no early-hints manifest exists for the page/language combination;
 * - the HTML contains INT_SCRIPT markers (handled by StaticHtmlWriterService).
 */
#[AsEventListener(
    identifier: 'mai-assets/static-html-writer',
    after: 'mai-assets/html-minification',
)]
final class StaticHtmlWriterListener
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly StaticHtmlWriterService $staticHtmlWriterService,
        private readonly PageOptimizationReadinessService $readinessService,
        private readonly EarlyHintCacheService $earlyHintCacheService,
    ) {}

    public function __invoke(AfterCacheableContentIsGeneratedEvent $event): void
    {
        if (!$this->extensionConfiguration->isEnableStaticFileCache()) {
            return;
        }

        if (!$event->isCachingEnabled()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->getMethod() !== 'GET') {
            return;
        }

        $requestUri = (string)$request->getUri();
        if ($this->hasQueryString($requestUri)) {
            return;
        }

        $pageArguments = $request->getAttribute('routing');
        if ($pageArguments === null) {
            return;
        }

        $pageUid = (int)$pageArguments->getPageId();
        if ($pageUid <= 0) {
            return;
        }

        if (!$this->readinessService->isReady($pageUid)) {
            return;
        }

        $language = $request->getAttribute('language');
        $languageUid = $language !== null ? (int)$language->getLanguageId() : 0;

        if ($this->earlyHintCacheService->load($pageUid, $languageUid) === []) {
            return;
        }

        $this->staticHtmlWriterService->writeByPageId(
            $event->getContent(),
            $pageUid,
            $languageUid,
        );
    }

    /**
     * Return true when the URI carries a non-empty query string.
     */
    private function hasQueryString(string $uri): bool
    {
        $parts = parse_url($uri);

        return isset($parts['query']) && $parts['query'] !== '';
    }
}
