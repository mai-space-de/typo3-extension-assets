<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Register above-fold cache
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mai_assets_above_fold'] = [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend'  => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
    'groups'   => ['system'],
];

// Register general assets cache (SVG inline, etc.)
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mai_assets'] ??= [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend'  => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
    'groups'   => ['pages'],
];

// Register transient memory cache for compiled asset paths (per-request)
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mai_assets_compiled'] ??= [
    'backend' => \TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend::class,
];

// Register Early Hints manifest cache (keyed by page + language)
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mai_assets_early_hints'] ??= [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend'  => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
    'groups'   => ['pages'],
];

// Register DataHandler hook
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
    = \Maispace\MaiAssets\Hook\ContentElementSaveHook::class;

// Include TypoScript static files (legacy registration, ext_tables.php handles static template)
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptConstants(
    '@import "EXT:mai_assets/Configuration/TypoScript/constants.typoscript"'
);
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup(
    '@import "EXT:mai_assets/Configuration/TypoScript/setup.typoscript"'
);

// Register YAML placeholder processor for asset paths
$GLOBALS['TYPO3_CONF_VARS']['SYS']['yamlLoader']['placeholderProcessors'][\Maispace\MaiAssets\Configuration\AssetPlaceholderProcessor::class] = [];
