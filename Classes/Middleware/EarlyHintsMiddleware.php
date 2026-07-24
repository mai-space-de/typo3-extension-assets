<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Middleware;

use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;

final class EarlyHintsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly EarlyHintCacheService $earlyHintCacheService,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $pageArguments = $request->getAttribute('routing');
        $language = $request->getAttribute('language');
        $candidates = [];

        // Skip Early Hints entirely in Development: Apache mod_proxy_fcgi auto-promotes
        // Link: headers to HTTP 103 responses before mod_headers can suppress them,
        // causing downstream proxies (e.g. Traefik) to fail with 500.
        if ($pageArguments !== null && $this->isCacheableGetRequest($request) && !Environment::getContext()->isDevelopment()) {
            $pageUid = (int)$pageArguments->getPageId();
            $languageUid = $language !== null ? (int)$language->getLanguageId() : 0;

            $candidates = $this->earlyHintCacheService->load($pageUid, $languageUid);

            // HTTP 103 informational responses break Hetzner managed-hosting TLS
            // front-ends (body delivered without status line → curl HTTP/0.9).
            // Send 103 only when safe; otherwise attach Link on the final 200.
            if ($candidates !== [] && $this->shouldSendHttp103()) {
                $this->sendEarlyHints($candidates);
                $candidates = [];
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
        $context = (string)Environment::getContext();

        return !str_contains($context, 'Hetzner');
    }

    /**
     * @param array<int, \Maispace\MaiAssets\EarlyHints\EarlyHintCandidate> $candidates
     */
    private function sendEarlyHints(array $candidates): void
    {
        if (headers_sent()) {
            return;
        }

        foreach ($candidates as $candidate) {
            header('Link: ' . $candidate->toLinkHeaderValue(), false, 103);
        }
    }

    /**
     * Attach preload hints as Link response headers on the final (200) response.
     *
     * Used when HTTP 103 is unsafe for the current hosting/proxy stack.
     *
     * @param array<int, \Maispace\MaiAssets\EarlyHints\EarlyHintCandidate> $candidates
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
