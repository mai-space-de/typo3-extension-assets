<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Middleware;

use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class EarlyHintsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly EarlyHintCacheService $earlyHintCacheService,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $pageArguments = $request->getAttribute('routing');
        $language = $request->getAttribute('language');

        if ($pageArguments !== null && $this->isCacheableGetRequest($request)) {
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
