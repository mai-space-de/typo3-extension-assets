<?php

declare(strict_types=1);

return [
    'frontend' => [
        'maispace/mai-assets/above-fold-report' => [
            'target' => \Maispace\MaiAssets\Middleware\AboveFoldReportMiddleware::class,
            'after' => ['typo3/cms-frontend/site'],
            'before' => ['typo3/cms-frontend/page-resolver'],
        ],
        'maispace/mai-assets/early-hints' => [
            'target' => \Maispace\MaiAssets\Middleware\EarlyHintsMiddleware::class,
            'after' => ['typo3/cms-frontend/page-resolver'],
            'before' => ['typo3/cms-frontend/page-argument-validator'],
        ],
    ],
];
