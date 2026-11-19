<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Middleware;

use Maispace\MaiAssets\EarlyHints\EarlyHintCandidate;
use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\StaticFileCache\StaticCacheRequestGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;

final readonly class EarlyHintsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private EarlyHintCacheService $earlyHintCacheService,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $pageArguments = $request->getAttribute('routing');
        $language = $request->getAttribute('language');
        $candidates = [];

        // Skip Early Hints entirely in Development: Apache mod_proxy_fcgi auto-promotes
        // Link: headers to HTTP 103 responses before mod_headers can suppress them,
        // causing downstream proxies (e.g. Traefik) to fail with 500.
        if (StaticCacheRequestGuard::isPlainPageDocument($pageArguments)
            && $this->isCacheableGetRequest($request)
            && !Environment::getContext()->isDevelopment()
        ) {
            $pageUid = (int) $pageArguments->getPageId();
            $languageUid = $language !== null ? (int) $language->getLanguageId() : 0;

            $candidates = $this->earlyHintCacheService->load($pageUid, $languageUid);

            // A real HTTP 103 needs SAPI support (headers_send(), e.g. FrankenPHP). Under
            // PHP-FPM header('Link: …', false, 103) sent no informational response; it only
            // left raw Link headers that showed up on the final response a second time.
            // Proxies and CDNs (Cloudflare, Apache H2EarlyHints) derive 103 from the Link
            // headers of the final response instead.
            if ($candidates !== [] && $this->shouldSendHttp103()) {
                $this->sendEarlyHints($candidates);
            }
        }

        $response = $handler->handle($request);

        if ($candidates !== []) {
            $response = $this->withLinkHeaders($response, $candidates);
        }

        return $response;
    }

    private function isCacheableGetRequest(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'GET';
    }

    /**
     * Whether raw PHP HTTP 103 Early Hints are safe for the current context.
     *
     * Hetzner contexts (e.g. Production/Hetzner) must not emit 103 — the
     * hosting reverse proxy mishandles informational responses.
     */
    private function shouldSendHttp103(): bool
    {
        if (!function_exists('headers_send')) {
            return false;
        }

        $context = (string) Environment::getContext();

        return !str_contains($context, 'Hetzner');
    }

    /**
     * Sends a real 103 Early Hints response (SAPIs with headers_send()). The raw
     * headers are removed again afterwards: the final response carries the same
     * Link headers through the PSR-7 response.
     *
     * @param array<int, EarlyHintCandidate> $candidates
     */
    private function sendEarlyHints(array $candidates): void
    {
        if (headers_sent()) {
            return;
        }

        foreach ($candidates as $candidate) {
            header('Link: ' . $candidate->toLinkHeaderValue(), false);
        }
        // Not part of PHP core (FrankenPHP and similar SAPIs), hence the dynamic call.
        \call_user_func('headers_send', 103);
        header_remove('Link');
    }

    /**
     * Attach preload hints as Link response headers on the final (200) response.
     *
     * Always used: clients, proxies and CDNs read the hints from the final response.
     *
     * @param array<int, EarlyHintCandidate> $candidates
     */
    private function withLinkHeaders(ResponseInterface $response, array $candidates): ResponseInterface
    {
        $linkValues = [];
        foreach ($candidates as $candidate) {
            $linkValues[] = $candidate->toLinkHeaderValue();
        }

        if ($linkValues === []) {
            return $response;
        }

        return $response->withHeader('Link', implode(', ', $linkValues));
    }
}
