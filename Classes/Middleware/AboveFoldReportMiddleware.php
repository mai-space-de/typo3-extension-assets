<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Middleware;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Security\AboveFoldTokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\JsonResponse;

final readonly class AboveFoldReportMiddleware implements MiddlewareInterface
{
    private const string ROUTE_PATH = '/api/mai-assets/above-fold-report';
    private const int RATE_LIMIT_MAX   = 10;
    private const int RATE_LIMIT_WINDOW = 60;

    public function __construct(
        private AboveFoldCacheService $aboveFoldCacheService,
        private ExtensionConfiguration $extensionConfiguration,
        private AboveFoldTokenService $tokenService,
        private CacheManager $cacheManager,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only handle our specific route
        $path = $request->getUri()->getPath();
        if ($path !== self::ROUTE_PATH) {
            return $handler->handle($request);
        }

        // Method must be POST
        if ($request->getMethod() !== 'POST') {
            return $handler->handle($request);
        }

        // Enforce Content-Type: application/json
        if (!str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            return new JsonResponse(['status' => 'unsupported_media_type'], 415);
        }

        // IP-based rate limiting
        $rateLimitResponse = $this->checkRateLimit($request);
        if ($rateLimitResponse instanceof ResponseInterface) {
            return $rateLimitResponse;
        }

        $body = (string)$request->getBody();

        if (strlen($body) > 4096) {
            return new JsonResponse(['status' => 'invalid', 'errors' => ['Payload too large']], 413);
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            return new JsonResponse(
                ['status' => 'invalid', 'errors' => ['Request body must be valid JSON']],
                400
            );
        }

        $token   = (string)($data['token'] ?? '');
        $ts      = (int)($data['resetTimestamp'] ?? 0);
        $pageUid = (int)($data['pageUid'] ?? 0);

        if (!$this->tokenService->verify($token, $pageUid, $ts)) {
            return new JsonResponse(['status' => 'forbidden'], 403);
        }

        $errors = $this->validate($data);
        if ($errors !== []) {
            return new JsonResponse(
                ['status' => 'invalid', 'errors' => $errors],
                400
            );
        }

        $pageUid = (int)$data['pageUid'];
        $bucket = (string)$data['bucket'];
        $criticalUids = array_map(intval(...), (array)$data['criticalUids']);

        $changed = $this->aboveFoldCacheService->updateCriticalUids($pageUid, $bucket, $criticalUids);

        if ($changed) {
            $this->cacheManager->flushCachesByTag('pageId_' . $pageUid);
        }

        return new JsonResponse(['status' => 'ok', 'changed' => $changed]);
    }

    private function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['pageUid']) || !is_numeric($data['pageUid']) || (int)$data['pageUid'] <= 0) {
            $errors[] = 'pageUid must be a positive integer';
        }

        if (!isset($data['criticalUids']) || !is_array($data['criticalUids'])) {
            $errors[] = 'criticalUids must be an array of integers';
        } elseif (!$this->isArrayOfIntegers($data['criticalUids'])) {
            $errors[] = 'criticalUids must contain only integers';
        } elseif (count($data['criticalUids']) > 50) {
            $errors[] = 'criticalUids must not contain more than 50 entries';
        }

        $validBuckets = array_keys($this->extensionConfiguration->getViewportBuckets());
        if (!isset($data['bucket']) || !in_array($data['bucket'], $validBuckets, true)) {
            $errors[] = sprintf('bucket must be one of: %s', implode(', ', $validBuckets));
        }

        return $errors;
    }

    private function isArrayOfIntegers(array $array): bool
    {
        return array_all($array, fn($item) => (is_int($item) || ctype_digit((string)$item)) && (int)$item > 0);
    }

    private function checkRateLimit(ServerRequestInterface $request): ?ResponseInterface
    {
        try {
            $cache = $this->cacheManager->getCache('mai_assets_above_fold');
            $ip    = (string)($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
            $key   = 'ratelimit_' . md5($ip);

            $count = $cache->get($key);
            $count = is_int($count) ? $count + 1 : 1;

            if ($count > self::RATE_LIMIT_MAX) {
                return new JsonResponse(['status' => 'too_many_requests'], 429);
            }

            $cache->set($key, $count, [], self::RATE_LIMIT_WINDOW);
        } catch (\Throwable) {
            // Fail open: cache unavailable, allow the request
        }

        return null;
    }
}
