<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Middleware;

use Maispace\MaiAssets\Service\HtmlMinificationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Minifies the final HTML response after PageRenderer substitutions and INT
 * content have been inlined.
 *
 * {@see HtmlMinificationListener} already shrinks cacheable page content, but
 * meta tags, CSS/JS includes and USER_INT/COA_INT output are injected afterwards
 * via PageRenderer placeholders — those still carry newlines. This middleware
 * runs a second pass on the completed ``text/html`` body.
 */
final readonly class HtmlMinificationMiddleware implements MiddlewareInterface
{
    /** Code of the RuntimeException thrown by FrontendTypoScript::getSetupArray() in cached scope. */
    private const int SETUP_NOT_INITIALIZED = 1666513645;

    public function __construct(
        private HtmlMinificationService $htmlMinificationService,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $response = $handler->handle($request);

        $contentType = $response->getHeaderLine('Content-Type');
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'text/html')) {
            return $response;
        }

        $tsSettings = $this->resolveSettings($request);

        if (empty($tsSettings['enable']) || $this->wasMinifiedByListener($request)) {
            return $response;
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $html = $body->getContents();

        if ($html === '') {
            return $response;
        }

        $minified = $this->htmlMinificationService->minify($html, $tsSettings);

        if ($minified === $html) {
            return $response;
        }

        $response = $response->withBody($this->streamFactory->createStream($minified));

        if ($response->hasHeader('Content-Length')) {
            $response = $response->withHeader('Content-Length', (string) strlen($minified));
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSettings(ServerRequestInterface $request): array
    {
        $frontendTypoScript = $request->getAttribute('frontend.typoscript');
        if (!is_object($frontendTypoScript) || !method_exists($frontendTypoScript, 'getSetupArray')) {
            return [];
        }

        try {
            $setup = $frontendTypoScript->getSetupArray();
        } catch (\RuntimeException $exception) {
            // Page cache hit: TYPO3 does not load the full setup for fully cached
            // pages. The cacheable content was already minified by
            // HtmlMinificationListener when the cache entry was written.
            if ($exception->getCode() === self::SETUP_NOT_INITIALIZED) {
                return [];
            }

            throw $exception;
        }

        $settings = $setup['plugin.']['tx_maispace_assets.']['settings.']['htmlMinification.'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    /**
     * The cacheable page content is minified by HtmlMinificationListener already. Only
     * non-cached parts (USER_INT) or pages rendered without page cache need this pass.
     */
    private function wasMinifiedByListener(ServerRequestInterface $request): bool
    {
        $pageParts = $request->getAttribute('frontend.page.parts');
        if (!is_object($pageParts) || !method_exists($pageParts, 'hasNotCachedContentElements')
            || $pageParts->hasNotCachedContentElements()
        ) {
            return false;
        }

        if (method_exists($pageParts, 'hasPageContentBeenLoadedFromCache') && $pageParts->hasPageContentBeenLoadedFromCache()) {
            return true;
        }

        $collector = $request->getAttribute('frontend.cache.collector');
        if (!is_object($collector) || !method_exists($collector, 'getCacheEntries')) {
            return false;
        }
        foreach ($collector->getCacheEntries() as $entry) {
            if (is_object($entry) && property_exists($entry, 'identifier') && $entry->identifier === 'tsfe-page-cache') {
                return true;
            }
        }

        return false;
    }
}
