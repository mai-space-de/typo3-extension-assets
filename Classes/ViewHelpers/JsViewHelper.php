<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\ViewHelpers;

use Closure;
use Maispace\MaispaceAssets\Service\AssetProcessingService;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;

/**
 * Include a JavaScript asset from a file path or inline Fluid content.
 *
 * The asset is processed (optionally minified), cached, written to typo3temp/,
 * and registered with TYPO3's AssetCollector.
 *
 * By default, external JS files are placed in the footer (priority=false) and
 * loaded with defer (following the TypoScript default js.defer = 1). This keeps
 * JavaScript non-render-blocking without any extra configuration.
 *
 * Global namespace: declared as "ma" in ext_localconf.php.
 *
 * Usage examples:
 *
 *   <!-- Inline JS written directly in the template (auto-identifier from content hash) -->
 *   <ma:js identifier="page-init">
 *       document.addEventListener('DOMContentLoaded', function() {
 *           console.log('page ready');
 *       });
 *   </ma:js>
 *
 *   <!-- External JS file with defer (default when TypoScript js.defer = 1) -->
 *   <ma:js src="EXT:theme/Resources/Public/JavaScript/app.js" />
 *
 *   <!-- ES module (type="module" implies defer automatically) -->
 *   <ma:js src="EXT:theme/Resources/Public/JavaScript/app.js" type="module" />
 *
 *   <!-- External JS forced to load async -->
 *   <ma:js src="EXT:theme/Resources/Public/JavaScript/analytics.js" async="true" />
 *
 *   <!-- Critical JS in <head>, no defer -->
 *   <ma:js src="EXT:theme/Resources/Public/JavaScript/polyfills.js"
 *          priority="true" defer="false" />
 *
 * @see \Maispace\MaispaceAssets\Service\AssetProcessingService::handleJs()
 */
final class JsViewHelper extends AbstractAssetViewHelper
{
    public function initializeArguments(): void
    {
        parent::initializeArguments();

        $this->registerArgument(
            'defer',
            'bool',
            'Add the defer attribute to the <script> tag. Null (default) uses the TypoScript setting plugin.tx_maispace_assets.js.defer (default: 1). Deferred scripts execute after the document is parsed, in order.',
            false,
            null,
        );

        $this->registerArgument(
            'async',
            'bool',
            'Add the async attribute to the <script> tag. The script is fetched in parallel and executed as soon as it is available (potentially before the document finishes parsing). Cannot be combined meaningfully with defer.',
            false,
            false,
        );

        $this->registerArgument(
            'type',
            'string',
            'The type attribute for the <script> tag. Use "module" for ES modules (implies defer). Omit for classic scripts.',
            false,
            null,
        );
    }

    public static function renderStatic(
        array $arguments,
        Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext,
    ): void {
        AssetProcessingService::handleJs($arguments, $renderChildrenClosure());
    }
}
