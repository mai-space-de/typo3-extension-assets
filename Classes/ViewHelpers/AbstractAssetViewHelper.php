<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;

/**
 * Base class for all maispace_assets ViewHelpers that process CSS, JS, and SCSS.
 *
 * Registers the arguments common to all asset types:
 *  - identifier  Unique key used by TYPO3's AssetCollector. Auto-generated from content hash if omitted.
 *  - src         EXT: path or absolute path to a source file. Mutually exclusive with inline content.
 *  - priority    When true, the asset is placed in <head>. Default: false (footer).
 *  - minify      Override the TypoScript minify setting for this single asset.
 *
 * The CompileWithRenderStatic trait enables Fluid template compilation, which means
 * the ViewHelper is only instantiated once for compilation and subsequent calls go
 * through the static renderStatic() method directly — significantly faster.
 */
abstract class AbstractAssetViewHelper extends AbstractViewHelper
{
    use CompileWithRenderStatic;

    public function initializeArguments(): void
    {
        $this->registerArgument(
            'identifier',
            'string',
            'Unique identifier for TYPO3\'s AssetCollector. Auto-generated from a content hash when omitted, so the same content always maps to the same asset file.',
            false,
            null,
        );

        $this->registerArgument(
            'src',
            'string',
            'EXT: path (e.g. EXT:my_ext/Resources/Public/Css/app.css) or absolute file system path to the asset file. When provided, inline child node content is ignored.',
            false,
            null,
        );

        $this->registerArgument(
            'priority',
            'bool',
            'Place the asset in <head> when true. Default false places it in the footer. For CSS this controls the <link> placement; for JS it controls <script> placement.',
            false,
            false,
        );

        $this->registerArgument(
            'minify',
            'bool',
            'Minify the asset content. Null (default) uses the TypoScript setting plugin.tx_maispace_assets.{type}.minify. Pass true or false to override per asset.',
            false,
            null,
        );
    }
}
