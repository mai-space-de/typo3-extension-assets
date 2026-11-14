<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Middleware;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementKey;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementProfileResolver;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementRepository;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementScale;
use Maispace\MaiAssets\Security\ImageMeasureTokenService;
use Maispace\MaiAssets\Service\ImageMeasureScriptBuilder;
use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Receives the layout widths of images measured in the browser.
 *
 * The endpoint is public, so every request is treated as hostile:
 * POST + JSON only, tiny body, per-IP rate limit, HMAC token per image slot
 * (only slots issued by this installation are accepted), strict range checks
 * and a bounded storage (see {@see ImageMeasurementScale}).
 */
final readonly class ImageMeasureReportMiddleware implements MiddlewareInterface
{
    private const int MAX_BODY_BYTES = 4096;
    private const int MAX_ITEMS = 24;
    private const int RATE_LIMIT_MAX = 30;
    private const int RATE_LIMIT_WINDOW = 60;

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
        private ImageMeasureTokenService $tokenService,
        private ImageMeasurementRepository $repository,
        private ImageMeasurementProfileResolver $profileResolver,
        private CacheManager $cacheManager,
        private StaticFileRemovalService $staticFileRemovalService,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== ImageMeasureScriptBuilder::ENDPOINT || $request->getMethod() !== 'POST') {
            return $handler->handle($request);
        }

        if (!$this->extensionConfiguration->isEnableImageMeasurement()) {
            return new JsonResponse(['status' => 'disabled'], 404);
        }

        if (!str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            return new JsonResponse(['status' => 'unsupported_media_type'], 415);
        }

        $declaredLength = (int) $request->getHeaderLine('Content-Length');
        if ($declaredLength > self::MAX_BODY_BYTES) {
            return new JsonResponse(['status' => 'invalid', 'errors' => ['Payload too large']], 413);
        }

        if ($this->isRateLimited($request)) {
            return new JsonResponse(['status' => 'too_many_requests'], 429);
        }

        // Read at most one byte more than allowed, a lying Content-Length must not matter.
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $body = $stream->read(self::MAX_BODY_BYTES + 1);
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return new JsonResponse(['status' => 'invalid', 'errors' => ['Payload too large']], 413);
        }

        $data = json_decode($body, true, 4);
        if (!is_array($data)) {
            return new JsonResponse(['status' => 'invalid', 'errors' => ['Request body must be valid JSON']], 400);
        }

        $pageUid = $data['p'] ?? null;
        $viewport = $data['v'] ?? null;
        $items = $data['m'] ?? null;
        if (!is_int($pageUid) || $pageUid <= 0
            || !is_int($viewport) || $viewport < ImageMeasurementScale::MIN_VIEWPORT || $viewport > ImageMeasurementScale::MAX_VIEWPORT
            || !is_array($items) || !array_is_list($items) || $items === [] || count($items) > self::MAX_ITEMS
        ) {
            return new JsonResponse(['status' => 'invalid'], 400);
        }

        $viewportStep = ImageMeasurementScale::viewportStep($viewport);
        $accepted = 0;
        $changed = false;
        $seen = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = $item['k'] ?? null;
            $token = $item['t'] ?? null;
            $width = $item['w'] ?? null;

            if (!is_string($key) || !is_string($token) || !is_int($width)
                || !ImageMeasurementKey::isValid($key)
                || isset($seen[$key])
                || !$this->tokenService->verify($token, $pageUid, $key)
            ) {
                continue;
            }
            $cssWidth = ImageMeasurementScale::ceilToLadder($width);
            if ($cssWidth === null) {
                continue;
            }
            $seen[$key] = true;

            $before = $this->profileResolver->signature($key);
            if (!$this->repository->record($key, $viewportStep, $cssWidth)) {
                $accepted++;
                continue;
            }
            $accepted++;
            if ($before !== $this->profileResolver->signature($key)) {
                $changed = true;
            }
        }

        if ($accepted === 0) {
            return new JsonResponse(['status' => 'forbidden'], 403);
        }

        if ($changed) {
            // The rendered srcset/sizes of this page are outdated.
            $this->cacheManager->flushCachesByTag('pageId_' . $pageUid);
            try {
                $this->staticFileRemovalService->purgeByPageUid($pageUid);
            } catch (\Throwable) {
                // Static file cache is optional.
            }
        }

        return new JsonResponse(['status' => 'ok', 'changed' => $changed]);
    }

    private function isRateLimited(ServerRequestInterface $request): bool
    {
        try {
            $cache = $this->cacheManager->getCache('mai_assets_above_fold');
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
            $key = 'imgratelimit_' . md5($ip);

            $count = $cache->get($key);
            $count = is_int($count) ? $count + 1 : 1;
            if ($count > self::RATE_LIMIT_MAX) {
                return true;
            }
            $cache->set($key, $count, [], self::RATE_LIMIT_WINDOW);
        } catch (\Throwable) {
            // Fail open: cache unavailable.
        }

        return false;
    }
}
