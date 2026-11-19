<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Middleware;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\StaticFileCache\StaticCacheRequestGuard;
use Maispace\MaiAssets\StaticFileCache\StaticFileCacheDirectory;
use Maispace\MaiAssets\StaticFileCache\StaticFileIndex;
use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use Maispace\MaiAssets\StaticFileCache\StaticContentSecurityPolicy;
use Maispace\MaiAssets\StaticFileCache\StaticResponseHeaders;
use Maispace\MaiAssets\StaticFileCache\StaticHtmlWriterService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\StreamFactory;

/**
 * Serves a cached static HTML file from disk when available, falling
 * through to TYPO3 page rendering otherwise.
 *
 * The middleware intercepts cacheable GET requests early in the PSR-15
 * chain (before the timetracker) and returns the pre-compiled HTML
 * without booting TYPO3's full rendering pipeline. Content-Encoding
 * negotiation (brotli / gzip) is performed against the client's
 * Accept-Encoding header so the web server or PHP can deliver
 * pre-compressed variants directly.
 *
 * When the static file cache has a hit, the middleware additionally
 * loads the cached early hints manifest for the current page+language
 * and includes the asset preload hints as ``Link`` response headers.
 * This is the second half of the **hybrid delivery path**:
 *
 * 1. ``EarlyHintsMiddleware`` (runs earlier in the chain) sends an
 *    HTTP 103 informational response with the same ``Link`` headers.
 * 2. This middleware includes those ``Link`` headers on the 200
 *    response as a non-103 fallback, so clients or proxies that do
 *    not process 103 Early Hints still receive the preload hints.
 *
 * Guards:
 * - The static file cache must be enabled via extension configuration
 *   (``enableStaticFileCache = 1``).
 * - Only GET requests without a query string are eligible.
 * - The resolved file path must remain within the configured cache
 *   base directory (path-traversal protection).
 * - The index.html file must exist and be readable.
 *
 * When any guard fails or an exception is thrown, the middleware
 * delegates to the next handler without side effects (fail-open).
 *
 * @see StaticFileCacheDirectory
 * @see StaticHtmlWriterService
 * @see EarlyHintCacheService
 * @see EarlyHintsMiddleware
 * @see ExtensionConfiguration::isEnableStaticFileCache()
 */
final readonly class StaticFileServeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private StaticFileCacheDirectory $cacheDirectory,
        private ExtensionConfiguration $extensionConfiguration,
        private EarlyHintCacheService $earlyHintCacheService,
        private StaticCacheRequestGuard $requestGuard,
        private StaticFileIndex $staticFileIndex,
        private StaticFileRemovalService $staticFileRemovalService,
        private StaticHtmlWriterService $staticHtmlWriterService,
        private StaticContentSecurityPolicy $contentSecurityPolicy,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if (!$this->extensionConfiguration->isEnableStaticFileCache()) {
            return $handler->handle($request);
        }

        if (!$this->isCacheableGetRequest($request)) {
            return $handler->handle($request);
        }

        // Turbo / Mai Turbo frame requests must always hit the dynamic
        // rendering pipeline (partial HTML), never the full-page static cache.
        if ($this->isTurboPartialRequest($request)) {
            return $handler->handle($request);
        }

        // Never replay the shared file to authenticated, preview or foreign-host requests.
        if (!$this->requestGuard->isShareable($request)) {
            return $handler->handle($request);
        }

        try {
            $requestUri = (string) $request->getUri();

            // URIs with a query string produce ambiguous cache keys.
            if ($this->hasQueryString($requestUri)) {
                return $handler->handle($request);
            }

            // One cache file per page, language and URL variant: /news/article-a and
            // /news/article-b share the page id but have different route arguments.
            $pageArgs = $request->getAttribute('routing');
            $variant = StaticCacheRequestGuard::variantOf($pageArgs);
            if ($variant === null) {
                return $handler->handle($request);
            }

            $language = $request->getAttribute('language');
            $pageUid = (int) $pageArgs->getPageId();
            $languageUid = $language !== null ? (int) $language->getLanguageId() : 0;

            $filePath = $this->resolveCacheFilePath($pageUid, $languageUid, $variant);

            if ($filePath === null || !is_file($filePath) || !is_readable($filePath)) {
                return $handler->handle($request);
            }

            // The file is only valid as long as the page cache entry it was rendered from:
            // any page cache flush or the end of its lifetime removes the index entry.
            if (!$this->staticFileIndex->isValid($pageUid, $languageUid, $variant)) {
                $this->staticFileRemovalService->purgeVariant($pageUid, $languageUid, $variant);

                return $handler->handle($request);
            }

            $plainContent = file_get_contents($filePath);
            if (!is_string($plainContent) || !StaticHtmlWriterService::isCacheableHtmlDocument($plainContent)) {
                return $handler->handle($request);
            }

            // Headers of the rendered page (config.additionalHeaders, Cache-Control, …).
            $metadata = $this->staticHtmlWriterService->readMetadata($pageUid, $languageUid, $variant);
            $headers = StaticResponseHeaders::forDelivery($metadata['headers'], $metadata['expires'], time());
            $headers['Content-Type'] = ['text/html; charset=utf-8'];
            // The body depends on Accept-Encoding (br/gzip variants): shared caches must key on it.
            $headers['Vary'] = ['Accept-Encoding'];

            // --- Hybrid delivery: add preload Link headers from early hints manifest ---
            // The EarlyHintsMiddleware already sent these as HTTP 103; we repeat them on the
            // 200 response as a fallback for clients / proxies that do not support 103.
            $this->addEarlyHintLinkHeaders($pageUid, $languageUid, $variant, $headers);

            if ($this->extensionConfiguration->isDebugHeaders()) {
                $headers['X-Mai-Static-Cache'] = ['1'];
            }

            // Same Content-Security-Policy as a dynamically delivered page; the nonce stored
            // in the HTML is replaced by the one of this request.
            $response = $this->contentSecurityPolicy->apply(
                $request,
                new HtmlResponse($plainContent, 200, $headers),
                $metadata['nonce'],
            );

            // Pre-compressed variants cannot carry a request specific nonce, and PHP or
            // TYPO3 output compression would encode them a second time.
            if (StaticContentSecurityPolicy::hasPolicy($response) || $this->isOutputCompressed()) {
                return $response;
            }

            $encoding = $this->negotiateEncoding($filePath, $request);
            if ($encoding === null) {
                return $response;
            }

            return $response
                ->withHeader('Content-Encoding', $encoding['coding'])
                ->withBody((new StreamFactory())->createStream((string) file_get_contents($encoding['file'])));
        } catch (\Throwable) {
            // Fail open: any error during static file serving delegates
            // to the normal TYPO3 rendering path.
            return $handler->handle($request);
        }
    }

    /**
     * Only GET requests without query parameters are eligible for
     * static file serving.
     */
    private function isCacheableGetRequest(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'GET';
    }

    /**
     * Detect Hotwired Turbo frame / Mai Turbo context requests.
     *
     * These arrive without a query string when the signed context is sent
     * via the ``Mai-Turbo-Context`` header, so the query-string guard alone
     * is insufficient.
     */
    private function isTurboPartialRequest(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('Turbo-Frame') !== ''
            || $request->getHeaderLine('Mai-Turbo-Context') !== '';
    }

    /**
     * Absolute path of {baseDir}/{pageUid}_{languageUid}/index.html, or null when
     * it cannot be resolved inside the cache base directory.
     */
    private function resolveCacheFilePath(int $pageUid, int $languageUid, string $variant): ?string
    {
        if ($pageUid <= 0) {
            return null;
        }

        try {
            $pageDir = $this->cacheDirectory->getPageDirectoryById($pageUid, $languageUid, $variant);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $filePath = rtrim($pageDir, '/') . '/' . StaticHtmlWriterService::INDEX_FILENAME;

        return str_starts_with($filePath, $this->cacheDirectory->getAbsoluteBaseDirectory()) ? $filePath : null;
    }

    /**
     * Check whether the given URI carries a non-empty query string.
     */
    private function hasQueryString(string $uri): bool
    {
        $parts = parse_url($uri);

        return isset($parts['query']) && $parts['query'] !== '';
    }

    /**
     * Load the cached early hints manifest and append ``Link`` headers
     * to the response header array.
     *
     * This provides the non-103 fallback half of the hybrid delivery
     * path. The method is a no-op when:
     *
     * - The ``routing`` attribute is not available on the request
     *   (no page resolved).
     * - No early hints manifest exists for the current page+language.
     *
     * @param array<string, list<string>> $headers  Reference to the response headers array.
     */
    private function addEarlyHintLinkHeaders(
        int $pageUid,
        int $languageUid,
        string $variant,
        array &$headers,
    ): void {
        $candidates = $this->earlyHintCacheService->load($pageUid, $languageUid, $variant);
        if ($candidates === []) {
            return;
        }

        $linkValues = [];
        foreach ($candidates as $candidate) {
            $linkValues[] = $candidate->toLinkHeaderValue();
        }

        $headers['Link'] = [implode(', ', $linkValues)];
    }

    /**
     * Best pre-compressed variant the client accepts: brotli before gzip, codings
     * with q=0 count as refused.
     *
     * @return array{coding: string, file: string}|null
     */
    private function negotiateEncoding(string $filePath, ServerRequestInterface $request): ?array
    {
        $accepted = self::parseAcceptEncoding($request->getHeaderLine('Accept-Encoding'));

        foreach (['br' => '.br', 'gzip' => '.gz'] as $coding => $suffix) {
            $quality = $accepted[$coding] ?? $accepted['*'] ?? 0.0;
            if ($quality > 0.0 && is_file($filePath . $suffix) && is_readable($filePath . $suffix)) {
                return ['coding' => $coding, 'file' => $filePath . $suffix];
            }
        }

        return null;
    }

    /**
     * @return array<string, float> coding => quality
     */
    public static function parseAcceptEncoding(string $header): array
    {
        $codings = [];
        foreach (explode(',', strtolower($header)) as $part) {
            $segments = array_map(trim(...), explode(';', $part));
            $coding = array_shift($segments);
            if ($coding === '') {
                continue;
            }
            $quality = 1.0;
            foreach ($segments as $parameter) {
                if (preg_match('/^q\s*=\s*([0-9.]+)$/', $parameter, $matches) === 1) {
                    $quality = (float) $matches[1];
                }
            }
            $codings[$coding] = $quality;
        }

        return $codings;
    }

    private function isOutputCompressed(): bool
    {
        $zlib = strtolower((string) ini_get('zlib.output_compression'));

        return (int) ($GLOBALS['TYPO3_CONF_VARS']['FE']['compressionLevel'] ?? 0) > 0
            || ($zlib !== '' && $zlib !== '0' && $zlib !== 'off');
    }
}
