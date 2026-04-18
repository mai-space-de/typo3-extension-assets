<?php

declare(strict_types=1);

return [
    'frontend' => [
        'maispace/mai-assets/above-fold-report' => [
            'target' => \Maispace\MaiAssets\Middleware\AboveFoldReportMiddleware::class,
            'after' => ['typo3/cms-frontend/site'],
            'before' => ['typo3/cms-frontend/page-resolver'],
        ],
    ],
];
