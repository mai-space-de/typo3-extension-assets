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
 * - the page type is not the default HTML document (sitemap and other typeNums share the page id);
 * - the generated body is not an HTML document;
 * - the page is not optimisation-ready (missing viewport bucket data);
 * - no early-hints manifest exists for the page/language combination;
 * - the HTML contains INT_SCRIPT markers (handled by StaticHtmlWriterService).
 */
#[AsEventListener(
    identifier: 'mai-assets/static-html-writer',
    after: 'mai-assets/html-minification',
)]
final readonly class StaticHtmlWriterListener
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
        private StaticHtmlWriterService $staticHtmlWriterService,
        private PageOptimizationReadinessService $readinessService,
        private EarlyHintCacheService $earlyHintCacheService,
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

        $requestUri = (string) $request->getUri();
        if ($this->hasQueryString($requestUri)) {
            return;
        }

        $pageArguments = $request->getAttribute('routing');
        if ($pageArguments === null) {
            return;
        }

        $pageUid = (int) $pageArguments->getPageId();
        if ($pageUid <= 0) {
            return;
        }

        // Sitemap and other typeNums resolve to the same page id. Their body
        // must not replace the cached HTML document for that page.
        if (!$this->isDefaultPageType($pageArguments)) {
            return;
        }

        $content = $event->getContent();
        if (!StaticHtmlWriterService::isCacheableHtmlDocument($content)) {
            return;
        }

        if (!$this->readinessService->isReady($pageUid)) {
            return;
        }

        $language = $request->getAttribute('language');
        $languageUid = $language !== null ? (int) $language->getLanguageId() : 0;

        if ($this->earlyHintCacheService->load($pageUid, $languageUid) === []) {
            return;
        }

        $this->staticHtmlWriterService->writeByPageId(
            $content,
            $pageUid,
            $languageUid,
        );
    }

    /**
     * The static file cache stores one HTML document per page and language.
     * Only the default page type (0) is that document.
     */
    private function isDefaultPageType(object $pageArguments): bool
    {
        if (!method_exists($pageArguments, 'getPageType')) {
            return true;
        }

        $pageType = $pageArguments->getPageType();
        if ($pageType === null) {
            return true;
        }

        $pageType = (string) $pageType;

        return $pageType === '' || $pageType === '0';
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
