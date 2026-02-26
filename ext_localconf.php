<?php

declare(strict_types=1);

defined('TYPO3') or die();

call_user_func(static function (): void {
    // Register global Fluid ViewHelper namespace 'ma'.
    // This allows templates to use <ma:css>, <ma:js>, <ma:scss>, <ma:svgSprite>
    // without a {namespace} declaration at the top of each template.
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['namespaces']['ma'] =
        ['Maispace\\MaispaceAssets\\ViewHelpers'];

    // Register the maispace_assets caching framework cache.
    // Stores minified/compiled assets. Grouped with pages so a page cache flush
    // also clears processed assets — ensures editors see fresh output.
    if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['maispace_assets'])) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['maispace_assets'] = [
            'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
            'backend'  => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
            'options'  => [
                'defaultLifetime' => 0, // permanent until flushed
            ],
            'groups'   => ['pages', 'all'],
        ];
    }
});
