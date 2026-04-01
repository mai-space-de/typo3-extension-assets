<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Register static TypoScript template
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
    'mai_assets',
    'Configuration/TypoScript/',
    'Mai Assets'
);

// TCA overrides are loaded automatically from Configuration/TCA/Overrides/
