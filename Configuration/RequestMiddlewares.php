<?php

declare(strict_types=1);

use Maispace\MaispaceAssets\Middleware\SvgSpriteMiddleware;

/**
 * PSR-15 middleware registration for the maispace_assets extension.
 *
 * The SvgSpriteMiddleware intercepts requests to the configured sprite URL
 * (default: /maispace/sprite.svg) and serves the assembled SVG sprite document.
 *
 * Stack position:
 *   after  site-resolver  → TYPO3 site context and TypoScript are available,
 *                            so the configurable routePath can be read.
 *   before page-resolver  → short-circuits before any page lookup, keeping
 *                            the request lifecycle minimal for this endpoint.
 *
 * @see \Maispace\MaispaceAssets\Middleware\SvgSpriteMiddleware
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
    ],
];
