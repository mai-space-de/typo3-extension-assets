<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Service\PageOptimizationReadinessService;
use Maispace\MaiAssets\StaticFileCache\StaticCacheRequestGuard;
use Maispace\MaiAssets\StaticFileCache\StaticFileIndex;
use Maispace\MaiAssets\StaticFileCache\StaticHtmlWriterService;
use Psr\Http\Message\ServerRequestInterface;
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
 * - the request is not shareable (logged-in user, preview, host differing from the site base);
 * - the generated body is not an HTML document (e.g. an XML sitemap typeNum);
 * - the page is not optimisation-ready (missing viewport bucket data);
 * - the HTML contains INT_SCRIPT markers (handled by StaticHtmlWriterService).
 *
 * URLs of the same page with route arguments (news detail pages) or a typeNum are
 * stored as separate variants (see StaticCacheRequestGuard::variantOf()).
 *
 * Every written file is registered in the {@see StaticFileIndex} with the tags
 * and lifetime of the page cache entry, so it is invalidated together with it
 * (a news record's own cache tags included).
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
        private StaticCacheRequestGuard $requestGuard,
        private StaticFileIndex $staticFileIndex,
    ) {}

    public function __invoke(AfterCacheableContentIsGeneratedEvent $event): void
    {
        if (!$this->extensionConfiguration->isEnableStaticFileCache() || !$event->isCachingEnabled()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->getMethod() !== 'GET' || !$this->requestGuard->isShareable($request)) {
            return;
        }

        if ($this->hasQueryString((string) $request->getUri())) {
            return;
        }

        // Each URL of a page (plain page, news detail via route arguments, typeNum) is a
        // document of its own: page, language and variant key the file.
        $pageArguments = $request->getAttribute('routing');
        $variant = StaticCacheRequestGuard::variantOf($pageArguments);
        if ($variant === null) {
            return;
        }

        $pageUid = (int) $pageArguments->getPageId();
        if ($pageUid <= 0) {
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

        if (!$this->staticHtmlWriterService->writeByPageId($content, $pageUid, $languageUid, $variant)) {
            return;
        }

        [$tags, $lifetime] = $this->resolvePageCacheMetadata($request);
        $this->staticFileIndex->register($pageUid, $languageUid, $tags, $lifetime, $variant);
    }

    /**
     * Tags and lifetime of the page cache entry that is being written.
     *
     * @return array{0: list<string>, 1: int}
     */
    private function resolvePageCacheMetadata(ServerRequestInterface $request): array
    {
        $collector = $request->getAttribute('frontend.cache.collector');
        if (is_object($collector) && method_exists($collector, 'getCacheTags')) {
            $tags = [];
            foreach ($collector->getCacheTags() as $tag) {
                $name = is_object($tag) && property_exists($tag, 'name') ? $tag->name : $tag;
                if (is_string($name)) {
                    $tags[] = $name;
                }
            }
            $lifetime = method_exists($collector, 'resolveLifetime') ? (int) $collector->resolveLifetime() : 0;

            return [$tags, $lifetime];
        }

        // TYPO3 12: the frontend controller still holds tags and timeout.
        $frontend = $GLOBALS['TSFE'] ?? null;
        if (is_object($frontend) && method_exists($frontend, 'getPageCacheTags')) {
            $lifetime = method_exists($frontend, 'get_cache_timeout') ? (int) $frontend->get_cache_timeout() : 0;

            return [array_values(array_filter((array) $frontend->getPageCacheTags(), is_string(...))), $lifetime];
        }

        return [[], 0];
    }

    private function hasQueryString(string $uri): bool
    {
        $parts = parse_url($uri);

        return isset($parts['query']) && $parts['query'] !== '';
    }
}
