<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Middleware;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Service\PageOptimizationReadinessService;
use Maispace\MaiAssets\StaticFileCache\StaticCacheRequestGuard;
use Maispace\MaiAssets\StaticFileCache\StaticFileIndex;
use Maispace\MaiAssets\StaticFileCache\StaticHtmlWriterService;
use Maispace\MaiAssets\StaticFileCache\StaticResponseHeaders;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce;

/**
 * Writes the static HTML file from the finished response.
 *
 * Only here the page cache metadata is complete: TYPO3 adds the pageId tags,
 * the page's cache_tags and the lifetime (starttime/endtime) after
 * AfterCacheableContentIsGeneratedEvent. The file is written after a fresh
 * rendering and also after a page cache hit, so a page first rendered for a
 * logged-in editor still gets its static file with the next anonymous visit.
 *
 * Runs inside TYPO3's CSP middleware: the stored headers are those of the page,
 * the CSP is regenerated per delivery (the nonce in the body is replaced).
 */
final readonly class StaticFileWriterMiddleware implements MiddlewareInterface
{
    private const string PAGE_CACHE_ENTRY = 'tsfe-page-cache';

    public function __construct(
        private StaticHtmlWriterService $writer,
        private ExtensionConfiguration $extensionConfiguration,
        private StaticCacheRequestGuard $requestGuard,
        private PageOptimizationReadinessService $readinessService,
        private StaticFileIndex $staticFileIndex,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        try {
            $this->writeFromResponse($request, $response);
        } catch (\Throwable) {
            // The static file cache must never break the delivered page.
        }

        return $response;
    }

    private function writeFromResponse(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $pageParts = $request->getAttribute('frontend.page.parts');
        $collector = $request->getAttribute('frontend.cache.collector');
        if (!is_object($pageParts) || !method_exists($pageParts, 'hasNotCachedContentElements')
            || !is_object($collector) || !method_exists($collector, 'getCacheTags')
        ) {
            return;
        }

        if (!$this->extensionConfiguration->isEnableStaticFileCache()
            || $request->getMethod() !== 'GET'
            || $response->getStatusCode() !== 200
            || !$this->isHtml($response)
            || $request->getUri()->getQuery() !== ''
            || !$this->requestGuard->isShareable($request)
        ) {
            return;
        }

        // Non-cached parts (USER_INT/COA_INT) make the body visitor specific.
        if ($pageParts->hasNotCachedContentElements()) {
            return;
        }

        $fromCache = $pageParts->hasPageContentBeenLoadedFromCache();
        if (!$fromCache && !$this->isPageCacheWritten($collector)) {
            return;
        }

        $pageArguments = $request->getAttribute('routing');
        $variant = StaticCacheRequestGuard::variantOf($pageArguments);
        $pageUid = $variant !== null ? (int) $pageArguments->getPageId() : 0;
        if ($pageUid <= 0 || !$this->readinessService->isReady($pageUid)) {
            return;
        }

        $language = $request->getAttribute('language');
        $languageUid = $language !== null ? (int) $language->getLanguageId() : 0;

        // A page cache hit only fills a missing or invalid file.
        if ($fromCache && $this->staticFileIndex->isValid($pageUid, $languageUid, $variant)) {
            return;
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $html = $body->getContents();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        if (!StaticHtmlWriterService::isCacheableHtmlDocument($html)
            || !$this->writer->writeByPageId($html, $pageUid, $languageUid, $variant)
        ) {
            return;
        }

        $tags = [];
        foreach ($collector->getCacheTags() as $tag) {
            $name = is_object($tag) && property_exists($tag, 'name') ? $tag->name : $tag;
            if (is_string($name)) {
                $tags[] = $name;
            }
        }
        $lifetime = method_exists($collector, 'resolveLifetime') ? (int) $collector->resolveLifetime() : 0;
        $this->staticFileIndex->register($pageUid, $languageUid, $tags, $lifetime, $variant);

        $nonce = $request->getAttribute('nonce');
        $this->writer->writeMetadata($pageUid, $languageUid, $variant, [
            'headers' => StaticResponseHeaders::filterForStorage($response->getHeaders()),
            'nonce' => $nonce instanceof ConsumableNonce ? $nonce->value : '',
            'expires' => time() + StaticFileIndex::effectiveLifetime($lifetime),
        ]);
    }

    /**
     * True when TYPO3 is going to persist this rendering in the page cache
     * (not the case for no_cache or when a listener disabled caching).
     */
    private function isPageCacheWritten(object $collector): bool
    {
        if (!method_exists($collector, 'getCacheEntries')) {
            return false;
        }
        foreach ($collector->getCacheEntries() as $entry) {
            if (is_object($entry) && property_exists($entry, 'identifier') && $entry->identifier === self::PAGE_CACHE_ENTRY) {
                return true;
            }
        }

        return false;
    }

    private function isHtml(ResponseInterface $response): bool
    {
        $contentType = strtolower($response->getHeaderLine('Content-Type'));

        return $contentType === '' || str_contains($contentType, 'text/html');
    }
}
