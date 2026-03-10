<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Middleware;

use Maispace\MaispaceAssets\Registry\SpriteIconRegistry;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware that serves the SVG sprite document at a configurable URL path.
 *
 * The sprite is assembled from all `Configuration/SpriteIcons.php` files found in
 * loaded TYPO3 extensions (via SpriteIconRegistry) and cached in the TYPO3 caching
 * framework. The middleware adds strong HTTP caching headers to enable efficient
 * browser caching between page loads.
 *
 * Route path
 * ==========
 * Default: `/maispace/sprite.svg`
 * Configurable via TypoScript: `plugin.tx_maispace_assets.svgSprite.routePath`
 *
 * The path must not conflict with TYPO3 page slugs. Choose something clearly
 * technical (e.g. `/_assets/sprite.svg` or `/maispace/sprite.svg`).
 *
 * HTTP caching behaviour
 * ======================
 * - `Cache-Control: public, max-age=31536000, immutable`
 *   The sprite changes only when extension files change, which flushes the TYPO3 cache
 *   and produces a new ETag. A one-year `max-age` is therefore safe.
 * - `ETag: "sha1(spriteXml)"`
 *   Enables conditional requests: clients that already have the sprite send
 *   `If-None-Match`; if the ETag matches, the middleware returns `304 Not Modified`
 *   without re-transmitting the SVG body.
 * - `Vary: Accept-Encoding`
 *   Allows proxy caches to serve gzip-compressed variants separately.
 *
 * Registration
 * ============
 * Registered in `Configuration/RequestMiddlewares.php`, positioned in the `frontend`
 * stack after `site-resolver` (so the site context is available for TypoScript reading)
 * and before `page-resolver` (so we short-circuit before any page lookup).
 *
 * @see SpriteIconRegistry
 */
final class SvgSpriteMiddleware implements MiddlewareInterface
{
    private const DEFAULT_ROUTE_PATH = '/maispace/sprite.svg';
    private const CONTENT_TYPE = 'image/svg+xml; charset=utf-8';

    public function __construct(
        private readonly SpriteIconRegistry $registry,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $routePath = $this->resolveRoutePath($request);

        if ($request->getUri()->getPath() !== $routePath) {
            return $handler->handle($request);
        }

        $site = $request->getAttribute('site');
        $siteIdentifier = $site?->getIdentifier();
        $spriteXml = $this->registry->buildSprite($siteIdentifier);

        if ($spriteXml === '') {
            // No symbols registered — return a valid empty SVG rather than a 404.
            $spriteXml = '<svg xmlns="http://www.w3.org/2000/svg"></svg>';
        }

        $etag = '"' . sha1($spriteXml) . '"';

        // Honour If-None-Match for conditional GET requests.
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            return $this->responseFactory->createResponse(304)
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
        }

        // Apply Brotli (preferred) or gzip compression to the response body when
        // the client signals support via Accept-Encoding and compression is enabled.
        $body = $spriteXml;
        $contentEncoding = null;

        if ($this->resolveCompressionFlag($request, 'enable', true)) {
            $acceptedEncodings = $this->parseAcceptEncoding($request->getHeaderLine('Accept-Encoding'));

            if ($this->resolveCompressionFlag($request, 'brotli', true)
                && function_exists('brotli_compress')
                && isset($acceptedEncodings['br'])
            ) {
                // Use BROTLI_TEXT constant when available; fall back to its integer value (1)
                // when the brotli extension defines it differently or stubs are missing.
                $mode = defined('BROTLI_TEXT') ? BROTLI_TEXT : 1;
                $compressed = brotli_compress($spriteXml, 11, $mode);
                if ($compressed !== false) {
                    $body = $compressed;
                    $contentEncoding = 'br';
                }
            }

            if ($contentEncoding === null
                && $this->resolveCompressionFlag($request, 'gzip', true)
                && function_exists('gzencode')
                && isset($acceptedEncodings['gzip'])
            ) {
                $compressed = gzencode($spriteXml, 9);
                if ($compressed !== false) {
                    $body = $compressed;
                    $contentEncoding = 'gzip';
                }
            }
        }

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', self::CONTENT_TYPE)
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->withHeader('ETag', $etag)
            ->withHeader('Vary', 'Accept-Encoding')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        if ($contentEncoding !== null) {
            $response = $response->withHeader('Content-Encoding', $contentEncoding);
        }

        $response->getBody()->write($body);

        return $response;
    }

    /**
     * Parse an Accept-Encoding header value per RFC 7231 §5.3.4.
     *
     * Tokenises the comma-separated list, strips optional whitespace, and applies
     * q-value filtering: encodings with q=0 are explicitly rejected and are excluded
     * from the returned map. Matching is case-insensitive (normalised to lowercase).
     *
     * Returns an array keyed by lowercase encoding name (value is always true) for
     * O(1) membership testing via isset().
     *
     * @return array<string, true>
     */
    private function parseAcceptEncoding(string $headerValue): array
    {
        if ($headerValue === '') {
            return [];
        }

        $accepted = [];

        foreach (explode(',', $headerValue) as $token) {
            $parts = array_map('trim', explode(';', trim($token)));
            $coding = strtolower(array_shift($parts));

            if ($coding === '') {
                continue;
            }

            $q = 1.0;
            foreach ($parts as $param) {
                $pair = array_map('trim', explode('=', $param, 2));
                if (strtolower($pair[0]) === 'q' && isset($pair[1])) {
                    $q = (float)$pair[1];
                    break;
                }
            }

            if ($q > 0.0) {
                $accepted[$coding] = true;
            }
        }

        return $accepted;
    }

    /**
     * Resolve a boolean flag from plugin.tx_maispace_assets.compression.{key}.
     *
     * Falls back to $default when TypoScript is unavailable or the key is not set.
     */
    private function resolveCompressionFlag(ServerRequestInterface $request, string $key, bool $default): bool
    {
        /** @var \TYPO3\CMS\Core\TypoScript\FrontendTypoScript|null $frontendTypoScript */
        $frontendTypoScript = $request->getAttribute('frontend.typoscript');
        if ($frontendTypoScript === null) {
            return $default;
        }

        /** @var array<string, mixed> $setup */
        $setup = $frontendTypoScript->getSetupArray();
        $plugin = $setup['plugin.'] ?? null;
        if (!is_array($plugin)) {
            return $default;
        }
        $ext = $plugin['tx_maispace_assets.'] ?? null;
        if (!is_array($ext)) {
            return $default;
        }
        $compression = $ext['compression.'] ?? null;
        if (!is_array($compression)) {
            return $default;
        }

        return isset($compression[$key]) ? (bool)$compression[$key] : $default;
    }

    /**
     * Resolve the configured sprite route path.
     *
     * Reads `plugin.tx_maispace_assets.svgSprite.routePath` from the TypoScript setup
     * available in the request attribute (populated by TYPO3's site-resolver middleware).
     * Falls back to the hard-coded default if TypoScript is not available.
     */
    private function resolveRoutePath(ServerRequestInterface $request): string
    {
        /** @var \TYPO3\CMS\Core\TypoScript\FrontendTypoScript|null $frontendTypoScript */
        $frontendTypoScript = $request->getAttribute('frontend.typoscript');

        if ($frontendTypoScript === null) {
            return self::DEFAULT_ROUTE_PATH;
        }

        /** @var array<string, mixed> $setup */
        $setup = $frontendTypoScript->getSetupArray();

        $plugin = $setup['plugin.'] ?? null;
        if (!is_array($plugin)) {
            return self::DEFAULT_ROUTE_PATH;
        }
        $ext = $plugin['tx_maispace_assets.'] ?? null;
        if (!is_array($ext)) {
            return self::DEFAULT_ROUTE_PATH;
        }
        $sprite = $ext['svgSprite.'] ?? null;
        if (!is_array($sprite)) {
            return self::DEFAULT_ROUTE_PATH;
        }
        $routePath = $sprite['routePath'] ?? '';

        if (!is_string($routePath) || $routePath === '') {
            return self::DEFAULT_ROUTE_PATH;
        }

        // Normalise: ensure leading slash, no trailing slash.
        return '/' . ltrim(rtrim($routePath, '/'), '/');
    }
}
