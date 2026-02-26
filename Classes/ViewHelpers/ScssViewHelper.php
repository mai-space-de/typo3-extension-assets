<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\ViewHelpers;

use Closure;
use Maispace\MaispaceAssets\Service\AssetProcessingService;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;

/**
 * Compile SCSS to CSS server-side and include the result as a CSS asset.
 *
 * SCSS is compiled via scssphp (pure PHP, no Node.js required). The compiled CSS
 * is cached in the maispace_assets cache — for file-based SCSS the cache is
 * automatically invalidated when the source file changes (via filemtime).
 *
 * When minify = true (or the TypoScript default is 1), scssphp uses
 * OutputStyle::COMPRESSED which removes all whitespace — no redundant double-pass
 * through a CSS minifier is needed.
 *
 * Global namespace: declared as "ma" in ext_localconf.php.
 *
 * Usage examples:
 *
 *   <!-- Inline SCSS compiled server-side -->
 *   <ma:scss identifier="hero-theme">
 *       $primary: #e63946;
 *       $spacing: 1.5rem;
 *
 *       .hero {
 *           background: $primary;
 *           padding: $spacing;
 *           color: white;
 *       }
 *   </ma:scss>
 *
 *   <!-- SCSS from file (auto-invalidated cache when file changes) -->
 *   <ma:scss src="EXT:theme/Resources/Private/Scss/main.scss" />
 *
 *   <!-- SCSS file with additional import paths for @import partials -->
 *   <ma:scss src="EXT:theme/Resources/Private/Scss/main.scss"
 *            importPaths="EXT:theme/Resources/Private/Scss/Partials,EXT:base/Resources/Private/Scss" />
 *
 *   <!-- Inline SCSS rendered as <style> in <head> (critical styles) -->
 *   <ma:scss identifier="critical-theme" priority="true" inline="true">
 *       $font-size-base: 16px;
 *       body { font-size: $font-size-base; margin: 0; }
 *   </ma:scss>
 *
 *   <!-- SCSS loaded deferred as external CSS file -->
 *   <ma:scss src="EXT:theme/Resources/Private/Scss/non-critical.scss" deferred="true" />
 *
 * @see \Maispace\MaispaceAssets\Service\AssetProcessingService::handleScss()
 * @see \Maispace\MaispaceAssets\Service\ScssCompilerService
 */
final class ScssViewHelper extends AbstractAssetViewHelper
{
    public function initializeArguments(): void
    {
        parent::initializeArguments();

        $this->registerArgument(
            'inline',
            'bool',
            'Render the compiled CSS as an inline <style> tag instead of writing it to an external file and using a <link>.',
            false,
            false,
        );

        $this->registerArgument(
            'deferred',
            'bool',
            'Load the compiled CSS non-blocking using the media="print" onload swap trick. Null uses the TypoScript default.',
            false,
            null,
        );

        $this->registerArgument(
            'media',
            'string',
            'The media attribute for the generated <link> tag when not using inline mode.',
            false,
            'all',
        );

        $this->registerArgument(
            'importPaths',
            'string',
            'Comma-separated list of additional import paths for resolving SCSS @import and @use statements. Supports EXT: notation (e.g. "EXT:my_ext/Resources/Private/Scss/Partials"). For file-based src, the source file\'s directory is always available automatically.',
            false,
            null,
        );
    }

    public static function renderStatic(
        array $arguments,
        Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext,
    ): void {
        AssetProcessingService::handleScss($arguments, $renderChildrenClosure());
    }
}
