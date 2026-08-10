<?php

declare(strict_types=1);

use Maispace\MaiAssets\Middleware\StaticFileServeMiddleware;
use Maispace\MaiAssets\Middleware\AboveFoldReportMiddleware;
use Maispace\MaiAssets\Middleware\EarlyHintsMiddleware;
use Maispace\MaiAssets\Middleware\DebugHeadersMiddleware;
use Maispace\MaiAssets\Middleware\AddCspNonceMetaTagMiddleware;
use Maispace\MaiAssets\Middleware\HtmlMinificationMiddleware;

return [
    'frontend' => [
        'maispace/mai-assets/static-file-serve' => [
            'target' => StaticFileServeMiddleware::class,
            'after' => [
                'maispace/mai-assets/early-hints',
            ],
        ],
        'maispace/mai-assets/above-fold-report' => [
            'target' => AboveFoldReportMiddleware::class,
            'before' => ['typo3/cms-frontend/page-resolver'],
            'after' => ['typo3/cms-frontend/site'],
        ],
        'maispace/mai-assets/early-hints' => [
            'target' => EarlyHintsMiddleware::class,
            'after' => ['typo3/cms-frontend/page-resolver'],
            'before' => ['typo3/cms-frontend/page-argument-validator'],
        ],
        'maispace/mai-assets/debug-headers' => [
            'target' => DebugHeadersMiddleware::class,
            'after' => ['typo3/cms-frontend/csp-headers'],
        ],
        'maispace/mai-assets/html-minification' => [
            'target' => HtmlMinificationMiddleware::class,
            'after' => ['typo3/cms-frontend/csp-headers'],
        ],
        'maispace/mai-assets/add-csp-nonce-meta-tag' => [
            'target' => AddCspNonceMetaTagMiddleware::class,
            'after' => [
                'typo3/cms-frontend/csp-headers',
            ],
        ],
    ],
    'backend' => [
        'maispace/mai-assets/add-csp-nonce-meta-tag' => [
            'target' => AddCspNonceMetaTagMiddleware::class,
            'after' => [
                'typo3/cms-backend/csp-headers',
            ],
        ],
    ],
];
