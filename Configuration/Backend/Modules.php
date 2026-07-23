<?php

declare(strict_types=1);

use Maispace\MaiAssets\Controller\Backend\ReportController;

return [
    'maispace_mai_assets_report' => [
        'parent' => 'system',
        'access' => 'admin',
        'workspaces' => 'online',
        'path' => '/module/system/mai-assets-report',
        'iconIdentifier' => 'mai-backend-module',
        'labels' => 'LLL:EXT:mai_assets/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'MaiAssets',
        'controllerActions' => [
            ReportController::class => ['list', 'boost', 'support'],
        ],
    ],
];
