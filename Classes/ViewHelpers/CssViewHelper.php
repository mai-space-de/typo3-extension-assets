<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\ViewHelpers;

use Closure;
use Maispace\MaispaceAssets\Service\AssetProcessingService;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;

/**
 * Include a CSS asset from a file path or inline Fluid content.
 *
 * The asset is processed (optionally minified), cached, written to typo3temp/,
 * and registered with TYPO3's AssetCollector for output in the rendered page.
 *
 * Global namespace: declared as "mai" in ext_localconf.php.
 *
 * Usage examples:
 *
 *   <!-- Inline CSS written directly in the template -->
 *   <mai:css identifier="hero-styles">
 *       .hero { background: #e63946; color: #fff; padding: 4rem; }
 *   </mai:css>
 *
 *   <!-- CSS from a file (EXT: notation) -->
 *   <mai:css src="EXT:my_ext/Resources/Public/Css/app.css" />
 *
 *   <!-- Critical CSS inlined in <head> (not a <link> tag) -->
 *   <mai:css identifier="critical" priority="true" inline="true" minify="true">
 *       body { margin: 0; font-family: sans-serif; }
 *   </mai:css>
 *
 *   <!-- Non-critical CSS loaded deferred (media="print" swap trick) -->
 *   <mai:css src="EXT:theme/Resources/Public/Css/non-critical.css" deferred="true" />
 *
 *   <!-- Override minification for a single asset -->
 *   <mai:css src="EXT:theme/Resources/Public/Css/vendor.css" minify="false" />
 *
 * @see \Maispace\MaispaceAssets\Service\AssetProcessingService::handleCss()
 */
final class CssViewHelper extends AbstractAssetViewHelper
{
    public function initializeArguments(): void
    {
        parent::initializeArguments();

        $this->registerArgument(
            'inline',
            'bool',
            'Render the CSS as an inline <style> tag instead of a <link> to an external file. Useful for critical above-the-fold CSS that must not cause a render-blocking request.',
            false,
            false,
        );

        $this->registerArgument(
            'deferred',
            'bool',
            'Load the CSS non-blocking using the media="print" onload swap trick. The stylesheet is fetched without blocking rendering; media is switched to "all" after load. A <noscript> fallback is appended automatically. Null uses the TypoScript default (deferred = 1 by default).',
            false,
            null,
        );

        $this->registerArgument(
            'media',
            'string',
            'The media attribute for the generated <link> tag (e.g. "screen", "print", "(max-width: 768px)"). Defaults to "all".',
            false,
            'all',
        );
    }

    public static function renderStatic(
        array $arguments,
        Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext,
    ): void {
        AssetProcessingService::handleCss($arguments, $renderChildrenClosure());
    }
}
