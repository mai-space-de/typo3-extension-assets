<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\ViewHelpers;

use Closure;
use Maispace\MaispaceAssets\Service\SvgSpriteService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;

/**
 * Build and output an SVG sprite for bandwidth-efficient icon usage.
 *
 * This ViewHelper operates in three distinct modes controlled by its arguments:
 *
 * --- Mode 1: register ---
 * Register an SVG file as a sprite symbol. Call this wherever an SVG icon is used
 * (including inside loops and partials). Duplicate registrations are silently ignored.
 *
 *   <ma:svgSprite register="EXT:theme/Resources/Public/Icons/arrow.svg" />
 *   <ma:svgSprite register="EXT:theme/Resources/Public/Icons/close.svg" symbolId="icon-close" />
 *
 * --- Mode 2: render ---
 * Output the hidden SVG sprite container that defines all registered symbols.
 * Call this ONCE per page, at the very start of <body>, AFTER all partials that
 * register symbols have been rendered (or in a layout that renders content first).
 *
 * IMPORTANT: In TYPO3 Fluid layouts, the f:render(section="Content") call happens
 * before the layout's own markup is output. Place <ma:svgSprite render="true" />
 * at the top of your layout's <body> to ensure it appears first in the HTML output.
 *
 *   <body>
 *       <ma:svgSprite render="true" />
 *       <!-- page content with <use> references -->
 *   </body>
 *
 * --- Mode 3: use ---
 * Output a <svg><use href="#symbol-id"> reference. The referenced symbol must have
 * been registered before the sprite is rendered.
 *
 *   <ma:svgSprite use="icon-arrow" width="24" height="24" class="icon icon--arrow" />
 *   <ma:svgSprite use="icon-close" aria-label="Close dialog" />
 *
 * Accessibility:
 *   - Decorative icons (no aria-label): aria-hidden="true" is added automatically.
 *   - Meaningful icons: pass aria-label="Description" to add role="img" and the label.
 *   - The sprite container is always aria-hidden="true".
 *
 * Global namespace: declared as "ma" in ext_localconf.php.
 *
 * @see \Maispace\MaispaceAssets\Service\SvgSpriteService
 */
final class SvgSpriteViewHelper extends AbstractViewHelper
{
    use CompileWithRenderStatic;

    /**
     * Disable output escaping — this ViewHelper returns raw HTML/SVG markup.
     */
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        // --- Mode 1: register ---
        $this->registerArgument(
            'register',
            'string',
            'EXT: path or absolute path to an SVG file to register as a sprite symbol. The SVG\'s viewBox and inner content are extracted; the outer <svg> element is stripped.',
            false,
            null,
        );

        $this->registerArgument(
            'symbolId',
            'string',
            'Custom symbol ID for the registered SVG. When omitted, the ID is auto-derived from the filename prefixed with the TypoScript svgSprite.symbolIdPrefix (default: "icon-"). Example: "arrow.svg" → "icon-arrow".',
            false,
            null,
        );

        // --- Mode 2: render ---
        $this->registerArgument(
            'render',
            'bool',
            'Output the full hidden SVG sprite block containing all registered symbols. Call once per page at the top of <body>.',
            false,
            false,
        );

        // --- Mode 3: use ---
        $this->registerArgument(
            'use',
            'string',
            'Symbol ID to reference. Outputs <svg><use href="#id"></use></svg>.',
            false,
            null,
        );

        // Attributes for the <svg> wrapper in use mode.
        $this->registerArgument(
            'class',
            'string',
            'CSS class(es) for the <svg> element in use mode.',
            false,
            null,
        );

        $this->registerArgument(
            'width',
            'string',
            'width attribute for the <svg> element in use mode.',
            false,
            null,
        );

        $this->registerArgument(
            'height',
            'string',
            'height attribute for the <svg> element in use mode.',
            false,
            null,
        );

        $this->registerArgument(
            'aria-hidden',
            'string',
            'aria-hidden attribute. Defaults to "true" for decorative icons. Set to "false" together with aria-label to make the icon meaningful to screen readers.',
            false,
            null,
        );

        $this->registerArgument(
            'aria-label',
            'string',
            'Accessible label for the icon. When set, role="img" is added and aria-hidden is not output.',
            false,
            null,
        );

        $this->registerArgument(
            'title',
            'string',
            'Optional <title> element inside the <svg> for additional screen reader context.',
            false,
            null,
        );
    }

    public static function renderStatic(
        array $arguments,
        Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext,
    ): string {
        /** @var SvgSpriteService $service */
        $service = GeneralUtility::makeInstance(SvgSpriteService::class);

        // Mode 1: register a symbol.
        if ($arguments['register'] !== null) {
            $service->registerSymbol(
                (string)$arguments['register'],
                $arguments['symbolId'] !== null ? (string)$arguments['symbolId'] : null,
            );
            return '';
        }

        // Mode 2: render the full sprite block.
        if ($arguments['render'] === true) {
            return $service->renderSprite();
        }

        // Mode 3: output a <use> reference.
        if ($arguments['use'] !== null) {
            return $service->renderUseTag($arguments);
        }

        return '';
    }
}
