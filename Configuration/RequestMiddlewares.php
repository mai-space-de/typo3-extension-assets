<?php

declare(strict_types=1);

return [
    'frontend' => [
        'maispace/mai-assets/static-file-serve' => [
            'target' => \Maispace\MaiAssets\Middleware\StaticFileServeMiddleware::class,
            'after' => [
                'maispace/mai-assets/early-hints',
            ],
        ],
        'maispace/mai-assets/above-fold-report' => [
            'target' => \Maispace\MaiAssets\Middleware\AboveFoldReportMiddleware::class,
            'after' => ['typo3/cms-frontend/site'],
        ],
        'maispace/mai-assets/early-hints' => [
            'target' => \Maispace\MaiAssets\Middleware\EarlyHintsMiddleware::class,
            'after' => ['typo3/cms-frontend/page-resolver'],
            'before' => ['typo3/cms-frontend/page-argument-validator'],
        ],
        'maispace/mai-assets/debug-headers' => [
            'target' => \Maispace\MaiAssets\Middleware\DebugHeadersMiddleware::class,
            'after' => ['typo3/cms-frontend/page-resolver'],
            'before' => ['typo3/cms-frontend/page-argument-validator'],
        ],
    ],
];
