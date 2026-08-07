<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Backend\FileBackend;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use Maispace\MaiAssets\Hook\ContentElementSaveHook;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use Maispace\MaiAssets\Configuration\AssetPlaceholderProcessor;

defined('TYPO3') || die();

// Register above-fold cache
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mai_assets_above_fold'] = [
    'frontend' => VariableFrontend::class,
    'backend'  => Typo3DatabaseBackend::class,
    'groups'   => ['system'],
];

// Register general assets cache (SVG inline, etc.)
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mai_assets'] ??= [
    'frontend' => VariableFrontend::class,
    'backend'  => FileBackend::class,
    'groups'   => ['pages'],
];

// Register transient memory cache for compiled asset paths (per-request)
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mai_assets_compiled'] ??= [
    'backend' => TransientMemoryBackend::class,
];

// Register Early Hints manifest cache (keyed by page + language)
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mai_assets_early_hints'] ??= [
    'frontend' => VariableFrontend::class,
    'backend'  => Typo3DatabaseBackend::class,
    'groups'   => ['pages'],
];

// Register DataHandler hook
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
    = ContentElementSaveHook::class;

// Include TypoScript static files (legacy registration, ext_tables.php handles static template)
ExtensionManagementUtility::addTypoScriptConstants(
    '@import "EXT:mai_assets/Configuration/TypoScript/constants.typoscript"'
);
ExtensionManagementUtility::addTypoScriptSetup(
    '@import "EXT:mai_assets/Configuration/TypoScript/setup.typoscript"'
);

// Register YAML placeholder processor for asset paths
$GLOBALS['TYPO3_CONF_VARS']['SYS']['yamlLoader']['placeholderProcessors'][AssetPlaceholderProcessor::class] = [];
