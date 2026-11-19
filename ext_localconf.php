<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Backend\FileBackend;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use Maispace\MaiAssets\Hook\ContentElementSaveHook;
use Maispace\MaiAssets\Hook\StaticFileCacheFlushHook;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use Maispace\MaiAssets\Configuration\AssetPlaceholderProcessor;

defined('TYPO3') || die();

// Register above-fold cache
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mai_assets_above_fold'] = [
    'frontend' => VariableFrontend::class,
    'backend'  => Typo3DatabaseBackend::class,
    'groups'   => ['system'],
];

// Rate limits of the public report endpoints (one counter per IP and minute). Database
// by default; on busy sites point it to a memory backend (APCu, Redis) in settings.php.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mai_assets_rate_limit'] ??= [
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

// Validity index of the static HTML cache: shares tags and lifetime with the page cache
// and belongs to the "pages" group, so every page cache invalidation also invalidates it.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mai_assets_static_index'] ??= [
    'frontend' => VariableFrontend::class,
    'backend'  => Typo3DatabaseBackend::class,
    'groups'   => ['pages'],
];

// Global Fluid namespace "mai" (templates may still declare it themselves)
$GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['namespaces']['mai'] ??= [];
if (!in_array('Maispace\\MaiAssets\\ViewHelpers', $GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['namespaces']['mai'], true)) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['namespaces']['mai'][] = 'Maispace\\MaiAssets\\ViewHelpers';
}

// Register DataHandler hook
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
    = ContentElementSaveHook::class;

// Remove static HTML files as soon as DataHandler clears the page cache (record changes, clear cache menu)
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['clearCachePostProc'][]
    = StaticFileCacheFlushHook::class . '->clearCachePostProc';

// Include TypoScript static files (legacy registration, ext_tables.php handles static template)
ExtensionManagementUtility::addTypoScriptConstants(
    '@import "EXT:mai_assets/Configuration/TypoScript/constants.typoscript"'
);
ExtensionManagementUtility::addTypoScriptSetup(
    '@import "EXT:mai_assets/Configuration/TypoScript/setup.typoscript"'
);

// Register YAML placeholder processor for asset paths
$GLOBALS['TYPO3_CONF_VARS']['SYS']['yamlLoader']['placeholderProcessors'][AssetPlaceholderProcessor::class] = [];
