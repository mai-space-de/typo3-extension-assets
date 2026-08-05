<?php

declare(strict_types=1);

defined('TYPO3') or die();

// -----------------------------------------------------------------------
// YouTube consent fields
// Shown in the file metadata editor for online-media video files (YouTube).
// Editors use these to configure the GDPR consent gate that is rendered
// by the Resources/Private/Partials/File/YoutubeConsent.html partial.
// -----------------------------------------------------------------------
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns(
    'sys_file_metadata',
    [
        'tx_maiassets_youtube_consent_enabled' => [
            'exclude' => true,
            'label'   => 'LLL:EXT:mai_assets/Resources/Private/Language/locallang_db.xlf:sys_file_metadata.tx_maiassets_youtube_consent_enabled',
            'config'  => [
                'type'    => 'check',
                'default' => 1,
                'items'   => [
                    ['label' => 'LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.enabled'],
                ],
            ],
        ],
        'tx_maiassets_youtube_consent_text' => [
            'exclude' => true,
            'label'   => 'LLL:EXT:mai_assets/Resources/Private/Language/locallang_db.xlf:sys_file_metadata.tx_maiassets_youtube_consent_text',
            'config'  => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
                'eval' => 'trim',
            ],
        ],
        // ---------------------------------------------------------------
        // Icon source folder field
        // Shown for SVG files to declare which sprite source folder this
        // icon belongs to.  Used by the SpriteIcon partial and the backend
        // folder-select widget so editors can pick icons from the correct
        // source folder.
        // ---------------------------------------------------------------
        'tx_maiassets_icon_source_folder' => [
            'exclude' => true,
            'label'   => 'LLL:EXT:mai_assets/Resources/Private/Language/locallang_db.xlf:sys_file_metadata.tx_maiassets_icon_source_folder',
            'config'  => [
                'type'    => 'input',
                'size'    => 50,
                'max'     => 255,
                'eval'    => 'trim',
                'default' => '',
            ],
        ],
    ]
);

// Add YouTube consent palette and attach it after the "alternative" field
// for all records (editors will only fill it for YouTube videos).
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addFieldsToPalette(
    'sys_file_metadata',
    'mai_assets_youtube_consent',
    'tx_maiassets_youtube_consent_enabled, tx_maiassets_youtube_consent_text'
);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'sys_file_metadata',
    '--palette--;LLL:EXT:mai_assets/Resources/Private/Language/locallang_db.xlf:palette.mai_assets_youtube_consent;mai_assets_youtube_consent',
    '',
    'after:alternative'
);

// Add icon source folder field after the title field for all file types.
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'sys_file_metadata',
    'tx_maiassets_icon_source_folder',
    '',
    'after:title'
);
