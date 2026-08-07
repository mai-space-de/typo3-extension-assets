<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Middleware;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\Service\PageOptimizationReadinessService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Emits debug response headers for ops and QA inspection.
 *
 * When the ``debugHeaders`` extension configuration flag is enabled,
 * this middleware appends two headers to every frontend response:
 *
 * - ``X-Mai-Static-Ready: 1`` — the page has observer data for all
 *   configured viewport buckets AND a non-empty early-hints manifest,
 *   meaning the static-file cache readiness gate would allow caching.
 * - ``X-Mai-Static-Ready: 0`` — the page is not yet fully optimised
 *   and would be blocked by the readiness listener.
 *
 * The middleware is no-op when ``debugHeaders`` is disabled (the
 * default in production).
 *
 * Placement: registered *after* ``typo3/cms-frontend/page-resolver``
 * so that ``frontend.page.information`` is available on the request,
 * and *before* ``typo3/cms-frontend/page-argument-validator`` so that
 * the readiness check runs on every resolved page before any argument
 * validation short-circuits the pipeline.
 *
 * @see StaticFileCacheReadinessListener The readiness gate that uses the same criteria.
 * @see PageOptimizationReadinessService
 * @see EarlyHintCacheService
 */
final readonly class DebugHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
        private PageOptimizationReadinessService $readinessService,
        private EarlyHintCacheService $earlyHintCacheService,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $response = $handler->handle($request);

        if (!$this->extensionConfiguration->isDebugHeaders()) {
            return $response;
        }

        $pageInformation = $request->getAttribute('frontend.page.information');
        if (!is_object($pageInformation)) {
            return $response;
        }

        $pageUid = (int)($pageInformation->getPageRecord()['uid'] ?? 0);
        if ($pageUid <= 0) {
            return $response;
        }

        $language = $request->getAttribute('language');
        $languageUid = $language !== null ? (int)$language->getLanguageId() : 0;

        $isReady = $this->readinessService->isReady($pageUid)
            && $this->earlyHintCacheService->load($pageUid, $languageUid) !== [];

        return $response->withHeader('X-Mai-Static-Ready', $isReady ? '1' : '0');
    }
}
