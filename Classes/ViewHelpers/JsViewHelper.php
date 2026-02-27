<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\ViewHelpers;

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
 * Global namespace: declared as "mai" in ext_localconf.php.
 *
 * Usage examples:
 *
 *   <!-- Inline JS written directly in the template (auto-identifier from content hash) -->
 *   <mai:js identifier="page-init">
 *       document.addEventListener('DOMContentLoaded', function() {
 *           console.log('page ready');
 *       });
 *   </mai:js>
 *
 *   <!-- External JS file with defer (default when TypoScript js.defer = 1) -->
 *   <mai:js src="EXT:theme/Resources/Public/JavaScript/app.js" />
 *
 *   <!-- ES module (type="module" implies defer automatically) -->
 *   <mai:js src="EXT:theme/Resources/Public/JavaScript/app.js" type="module" />
 *
 *   <!-- External JS forced to load async -->
 *   <mai:js src="EXT:theme/Resources/Public/JavaScript/analytics.js" async="true" />
 *
 *   <!-- Critical JS in <head>, no defer -->
 *   <mai:js src="EXT:theme/Resources/Public/JavaScript/polyfills.js"
 *          priority="true" defer="false" />
 *
 * @see AssetProcessingService::handleJs()
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
            'The type attribute for the <script> tag. Use "module" for ES modules (implies defer). '
            . 'Use "importmap" together with inline content to emit an import map block. Omit for classic scripts.',
            false,
            null,
        );

        $this->registerArgument(
            'nonce',
            'string',
            'CSP nonce for the inline <script> tag. Only applied for inline JS (no src set). '
            . 'When TYPO3\'s built-in Content Security Policy is enabled (Install Tool → Content Security Policy), '
            . 'the nonce is read automatically from the request — no argument is needed. '
            . 'Pass an explicit value only to override the auto-detected nonce.',
            false,
            null,
        );

        $this->registerArgument(
            'integrity',
            'bool',
            'Automatically compute and add an SRI integrity attribute (sha384) to the generated <script> tag. '
            . 'Only applied for external file assets (not inline). '
            . 'Browsers will refuse to execute the script if its hash does not match.',
            false,
            null,
        );

        $this->registerArgument(
            'crossorigin',
            'string',
            'Value for the crossorigin attribute added alongside integrity. '
            . 'Defaults to "anonymous" when integrity is enabled. '
            . 'Use "use-credentials" for authenticated cross-origin requests.',
            false,
            null,
        );

        $this->registerArgument(
            'integrityValue',
            'string',
            'Pre-computed SRI hash for external scripts (e.g. "sha384-..."). '
            . 'Only used when src is an external URL, because the hash cannot be computed remotely at render time. '
            . 'Example: integrityValue="sha384-Fo3rlrZj/k7ujTeHg/9LZlB9xHqgSjQKtFXpgzH/vX8AAIM5B4YX7d3/9g=="',
            false,
            null,
        );

        $this->registerArgument(
            'nomodule',
            'bool',
            'Add the nomodule attribute to the <script> tag. '
            . 'Use for legacy (non-module) fallback bundles that should be ignored by browsers supporting ES modules. '
            . 'When true, defer and async are automatically suppressed (legacy parsers execute nomodule scripts immediately).',
            false,
            false,
        );
    }

    public static function renderStatic(
        array $arguments,
        \Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext,
    ): void {
        AssetProcessingService::handleJs($arguments, $renderChildrenClosure());
    }
}
