<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Middleware;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\StaticFileCache\StaticFileCacheDirectory;
use Maispace\MaiAssets\StaticFileCache\StaticHtmlWriterService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;

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
final class StaticFileServeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly StaticFileCacheDirectory $cacheDirectory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly EarlyHintCacheService $earlyHintCacheService,
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

        try {
            $requestUri = (string)$request->getUri();

            // URIs with a query string produce ambiguous cache keys.
            if ($this->hasQueryString($requestUri)) {
                return $handler->handle($request);
            }

            if (!$this->cacheDirectory->isValidUri($requestUri)) {
                return $handler->handle($request);
            }

            $pageDir  = $this->cacheDirectory->getPageDirectory($requestUri);
            $filePath = rtrim($pageDir, '/') . '/' . StaticHtmlWriterService::INDEX_FILENAME;

            // Path-traversal guard: the resolved path must live inside the
            // configured cache base directory.
            $baseDir = $this->cacheDirectory->getAbsoluteBaseDirectory();
            if (!str_starts_with($filePath, $baseDir)) {
                return $handler->handle($request);
            }

            if (!is_file($filePath) || !is_readable($filePath)) {
                return $handler->handle($request);
            }

            $headers = ['Content-Type' => 'text/html; charset=utf-8'];

            // --- Hybrid delivery: add preload Link headers from early hints manifest ---
            // The EarlyHintsMiddleware already sent these as HTTP 103; we repeat them on the
            // 200 response as a fallback for clients / proxies that do not support 103.
            $this->addEarlyHintLinkHeaders($request, $headers);

            $filePath = $this->resolveContentEncoding($filePath, $request, $headers);

            if ($this->extensionConfiguration->isDebugHeaders()) {
                $headers['X-Mai-Static-Cache'] = '1';
            }

            $content = file_get_contents($filePath);

            return new HtmlResponse((string)$content, 200, $headers);
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
     * @param array<string, string> $headers  Reference to the response headers array.
     */
    private function addEarlyHintLinkHeaders(
        ServerRequestInterface $request,
        array &$headers,
    ): void {
        $pageArguments = $request->getAttribute('routing');
        if ($pageArguments === null) {
            return;
        }

        $language = $request->getAttribute('language');
        $pageUid = (int)$pageArguments->getPageId();
        $languageUid = $language !== null ? (int)$language->getLanguageId() : 0;

        $candidates = $this->earlyHintCacheService->load($pageUid, $languageUid);
        if ($candidates === []) {
            return;
        }

        $linkValues = [];
        foreach ($candidates as $candidate) {
            $linkValues[] = $candidate->toLinkHeaderValue();
        }

        if ($linkValues !== []) {
            $headers['Link'] = implode(', ', $linkValues);
        }
    }

    /**
     * Negotiate the best Content-Encoding variant for the current request.
     *
     * Brotli is preferred over gzip when the client advertises both.
     * Returns the absolute path to the chosen variant and appends the
     * ``Content-Encoding`` header to the $headers array.
     *
     * @param array<string, string> $headers  Reference to the response headers array.
     */
    private function resolveContentEncoding(
        string $filePath,
        ServerRequestInterface $request,
        array &$headers,
    ): string {
        foreach ($request->getHeader('accept-encoding') as $acceptEncoding) {
            if (str_contains($acceptEncoding, 'br')) {
                if (is_file($filePath . '.br') && is_readable($filePath . '.br')) {
                    $headers['Content-Encoding'] = 'br';
                    return $filePath . '.br';
                }
            }
            if (str_contains($acceptEncoding, 'gzip')) {
                if (is_file($filePath . '.gz') && is_readable($filePath . '.gz')) {
                    $headers['Content-Encoding'] = 'gzip';
                    return $filePath . '.gz';
                }
            }
        }

        return $filePath;
    }
}
