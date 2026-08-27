<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die();

ExtensionManagementUtility::addTCAcolumns(
    'tt_content',
    [
        'tx_maiassets_force_critical' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mai_assets/Resources/Private/Language/locallang_db.xlf:tt_content.tx_maiassets_force_critical',
            'description' => 'LLL:EXT:mai_assets/Resources/Private/Language/locallang_db.xlf:tt_content.tx_maiassets_force_critical.description',
            'displayCond' => 'HIDE_FOR_NON_ADMINS',
            'config' => [
                'type' => 'check',
                'default' => 0,
                'items' => [
                    ['label' => 'LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.enabled'],
                ],
            ],
        ],
        'tx_maiassets_is_critical' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mai_assets/Resources/Private/Language/locallang_db.xlf:tt_content.tx_maiassets_is_critical',
            'description' => 'LLL:EXT:mai_assets/Resources/Private/Language/locallang_db.xlf:tt_content.tx_maiassets_is_critical.description',
            'displayCond' => [
                'AND' => [
                    'HIDE_FOR_NON_ADMINS',
                    'FIELD:tx_maiassets_force_critical:REQ:false',
                ],
            ],
            'config' => [
                'type' => 'check',
                'default' => 0,
                'items' => [
                    ['label' => 'LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.enabled'],
                ],
            ],
        ],
    ]
);

ExtensionManagementUtility::addFieldsToPalette(
    'tt_content',
    'mai_assets_critical',
    'tx_maiassets_force_critical, tx_maiassets_is_critical'
);

ExtensionManagementUtility::addToAllTCAtypes(
    'tt_content',
    '--div--;LLL:EXT:mai_assets/Resources/Private/Language/locallang_db.xlf:tabs.render_as_critical,'
    . '--palette--;LLL:EXT:mai_assets/Resources/Private/Language/locallang_db.xlf:palette.mai_assets_critical;mai_assets_critical'
);
