<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Add tx_maiassets_force_critical field
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns(
    'tt_content',
    [
        'tx_maiassets_force_critical' => [
            'exclude' => true,
            'label'   => 'LLL:EXT:mai_assets/Resources/Private/Language/locallang_db.xlf:tt_content.tx_maiassets_force_critical',
            'config'  => [
                'type'    => 'check',
                'default' => 0,
                'items'   => [
                    ['label' => 'LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.enabled'],
                ],
            ],
        ],
        'tx_maiassets_is_critical' => [
            'exclude'     => true,
            'label'       => 'LLL:EXT:mai_assets/Resources/Private/Language/locallang_db.xlf:tt_content.tx_maiassets_is_critical',
            'displayCond' => 'FIELD:tx_maiassets_force_critical:REQ:false',
            'config'      => [
                'type'    => 'check',
                'default' => 0,
                'items'   => [
                    ['label' => 'LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.enabled'],
                ],
            ],
        ],
    ]
);

// Add to palette and showitem for common content types
$criticalPalette = '
    --palette--;LLL:EXT:mai_assets/Resources/Private/Language/locallang_db.xlf:palette.mai_assets_critical;mai_assets_critical,
';

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addFieldsToPalette(
    'tt_content',
    'mai_assets_critical',
    'tx_maiassets_force_critical, tx_maiassets_is_critical'
);

// Add palette to all standard content types
$contentTypes = [
    'text',
    'textpic',
    'textmedia',
    'image',
    'bullets',
    'table',
    'uploads',
    'multimedia',
    'media',
    'html',
    'header',
    'shortcut',
    'list',
    'div',
    'default',
];

foreach ($contentTypes as $cType) {
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
        'tt_content',
        '--palette--;;mai_assets_critical',
        $cType,
        'after:header'
    );
}

// Fallback: add to all CTypes via generic approach
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'tt_content',
    'tx_maiassets_force_critical, tx_maiassets_is_critical',
    '',
    'after:header'
);
