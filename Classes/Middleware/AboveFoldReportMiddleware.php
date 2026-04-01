<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Middleware;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class AboveFoldReportMiddleware implements MiddlewareInterface
{
    private const ROUTE_PATH = '/api/mai-assets/above-fold-report';

    public function __construct(
        private readonly AboveFoldCacheService $aboveFoldCacheService,
        private readonly ExtensionConfiguration $extensionConfiguration,
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

        $body = (string)$request->getBody();
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return new JsonResponse(
                ['status' => 'invalid', 'errors' => ['Request body must be valid JSON']],
                400
            );
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
        $criticalUids = array_map('intval', (array)$data['criticalUids']);

        $changed = $this->aboveFoldCacheService->updateCriticalUids($pageUid, $bucket, $criticalUids);

        if ($changed) {
            $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
            $cacheManager->flushCachesByTag('pageId_' . $pageUid);
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
        }

        $validBuckets = array_keys($this->extensionConfiguration->getViewportBuckets());
        if (!isset($data['bucket']) || !in_array($data['bucket'], $validBuckets, true)) {
            $errors[] = sprintf('bucket must be one of: %s', implode(', ', $validBuckets));
        }

        return $errors;
    }

    private function isArrayOfIntegers(array $array): bool
    {
        foreach ($array as $item) {
            if (!is_int($item) && !ctype_digit((string)$item)) {
                return false;
            }
        }
        return true;
    }
}
