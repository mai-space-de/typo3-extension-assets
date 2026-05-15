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

        // Skip Early Hints in Development context: Apache mod_proxy_fcgi auto-promotes
        // Link: headers to HTTP 103 responses before mod_headers can suppress them,
        // causing downstream proxies (e.g. Traefik) to fail with 500.
        if ($pageArguments !== null && $this->isCacheableGetRequest($request) && !Environment::getContext()->isDevelopment()) {
            $pageUid = (int)$pageArguments->getPageId();
            $languageUid = $language !== null ? (int)$language->getLanguageId() : 0;

            $candidates = $this->earlyHintCacheService->load($pageUid, $languageUid);

            if ($candidates !== []) {
                $this->sendEarlyHints($candidates);
            }
        }

        return $handler->handle($request);
    }

    private function isCacheableGetRequest(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'GET';
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
}
