<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\ViewHelpers;

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
 * @see AssetProcessingService::handleCss()
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

        $this->registerArgument(
            'nonce',
            'string',
            'CSP nonce for the inline <style> tag. Only applied when inline="true". '
            . 'When TYPO3\'s built-in Content Security Policy is enabled (Install Tool → Content Security Policy), '
            . 'the nonce is read automatically from the request — no argument is needed. '
            . 'Pass an explicit value only to override the auto-detected nonce.',
            false,
            null,
        );

        $this->registerArgument(
            'integrity',
            'bool',
            'Automatically compute and add an SRI integrity attribute (sha384) to the generated <link> tag. '
            . 'Only applied for external file assets (not inline). '
            . 'Browsers will refuse to load the file if its hash does not match.',
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
            'Pre-computed SRI hash for external stylesheets (e.g. "sha384-..."). '
            . 'Only used when src is an external URL, because the hash cannot be computed remotely at render time. '
            . 'Example: integrityValue="sha384-Fo3rlrZj/k7ujTeHg/9LZlB9xHqgSjQKtFXpgzH/vX8AAIM5B4YX7d3/9g=="',
            false,
            null,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function renderStatic(
        array $arguments,
        \Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext,
    ): void {
        $inlineContent = $renderChildrenClosure();
        AssetProcessingService::handleCss($arguments, is_string($inlineContent) ? $inlineContent : null);
    }
}
