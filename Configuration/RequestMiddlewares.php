<?php

declare(strict_types=1);

use Maispace\MaispaceAssets\Middleware\CriticalCssInlineMiddleware;
use Maispace\MaispaceAssets\Middleware\SvgSpriteMiddleware;

/**
 * PSR-15 middleware registration for the maispace_assets extension.
 *
 * SvgSpriteMiddleware
 * ===================
 * Intercepts requests to the configured sprite URL (default: /maispace/sprite.svg)
 * and serves the assembled SVG sprite document.
 *
 * Stack position:
 *   after  site-resolver  → TYPO3 site context and TypoScript are available,
 *                            so the configurable routePath can be read.
 *   before page-resolver  → short-circuits before any page lookup, keeping
 *                            the request lifecycle minimal for this endpoint.
 *
 * CriticalCssInlineMiddleware
 * ===========================
 * Injects cached per-page critical CSS (mobile and desktop viewports) and
 * critical JS as inline <style>/<script> blocks immediately before </head>.
 *
 * Stack position:
 *   after  page-resolver  → PageArguments (page UID) are already set on the
 *                            request when process() is called. Calling
 *                            $handler->handle() triggers the full page render;
 *                            the resulting HTML response is then modified in-place.
 *
 * @see \Maispace\MaispaceAssets\Middleware\SvgSpriteMiddleware
 * @see \Maispace\MaispaceAssets\Middleware\CriticalCssInlineMiddleware
 * @see https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/RequestLifeCycle/Middlewares.html
 */
return [
    'frontend' => [
        'maispace-assets/svg-sprite' => [
            'target' => SvgSpriteMiddleware::class,
            'after'  => [
                'typo3/cms-frontend/site-resolver',
            ],
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
        'maispace-assets/critical-css-inline' => [
            'target' => CriticalCssInlineMiddleware::class,
            'after'  => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];
