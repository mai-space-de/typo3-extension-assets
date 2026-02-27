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

        $siteIdentifier = $request->getAttribute('site')?->getIdentifier();
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

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', self::CONTENT_TYPE)
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->withHeader('ETag', $etag)
            ->withHeader('Vary', 'Accept-Encoding')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        $response->getBody()->write($spriteXml);

        return $response;
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

        $setup = $frontendTypoScript->getSetupArray();
        $routePath = $setup['plugin.']['tx_maispace_assets.']['svgSprite.']['routePath'] ?? '';

        if (!is_string($routePath) || $routePath === '') {
            return self::DEFAULT_ROUTE_PATH;
        }

        // Normalise: ensure leading slash, no trailing slash.
        return '/' . ltrim(rtrim($routePath, '/'), '/');
    }
}
