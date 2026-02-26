<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\ViewHelpers;

use Closure;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;

/**
 * Wrap content in a `<figure>` element with an optional `<figcaption>`.
 *
 * Intended as a standalone semantic wrapper for images, videos, or any
 * content that benefits from a figure/caption structure. Deliberately kept
 * separate from `mai:picture` and `mai:image` so each ViewHelper has a single,
 * focused responsibility.
 *
 * Global namespace: declared as "mai" in ext_localconf.php.
 *
 * Usage examples:
 *
 *   <!-- Minimal wrapper, no caption -->
 *   <mai:figure>
 *       <mai:picture image="{file}" alt="{alt}" width="800" />
 *   </mai:figure>
 *
 *   <!-- With caption and custom classes -->
 *   <mai:figure class="article-figure" classFigcaption="caption">
 *       <mai:image image="{img}" alt="{alt}" width="600" />
 *       Photo: {photographer}
 *   </mai:figure>
 *
 *   <!-- Caption from a variable -->
 *   <mai:figure caption="{file.description}" class="content-figure">
 *       <mai:picture image="{file}" alt="{file.alternative}" width="1200" />
 *   </mai:figure>
 *
 * Note: When using the `caption` argument, the value is rendered as text (HTML-escaped).
 * For a caption containing markup, omit the `caption` argument and place a
 * `<figcaption>` element inside the ViewHelper's child content instead.
 */
final class FigureViewHelper extends AbstractViewHelper
{
    use CompileWithRenderStatic;

    /** Disable output escaping — this ViewHelper returns raw HTML. */
    protected $escapeOutput = false;

    /** Allow child ViewHelpers to render unescaped. */
    protected $escapeChildren = false;

    public function initializeArguments(): void
    {
        $this->registerArgument(
            'caption',
            'string',
            'Caption text rendered inside a <figcaption> element. HTML-escaped. For markup in the caption, omit this argument and write <figcaption> directly in the child content.',
            false,
            null,
        );

        $this->registerArgument(
            'class',
            'string',
            'CSS class(es) for the <figure> element.',
            false,
            null,
        );

        $this->registerArgument(
            'classFigcaption',
            'string',
            'CSS class(es) for the <figcaption> element. Only rendered when a caption is present.',
            false,
            null,
        );
    }

    public static function renderStatic(
        array $arguments,
        Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext,
    ): string {
        $children = (string)$renderChildrenClosure();

        $figureAttrs = '';
        if (!empty($arguments['class'])) {
            $figureAttrs = ' class="' . htmlspecialchars((string)$arguments['class'], ENT_QUOTES | ENT_XML1) . '"';
        }

        $figcaptionHtml = '';
        $caption = $arguments['caption'] ?? null;
        if ($caption !== null && $caption !== '') {
            $captionAttrs = '';
            if (!empty($arguments['classFigcaption'])) {
                $captionAttrs = ' class="' . htmlspecialchars((string)$arguments['classFigcaption'], ENT_QUOTES | ENT_XML1) . '"';
            }
            $figcaptionHtml = '<figcaption' . $captionAttrs . '>'
                . htmlspecialchars($caption, ENT_QUOTES | ENT_XML1)
                . '</figcaption>';
        }

        return '<figure' . $figureAttrs . '>'
            . $children
            . $figcaptionHtml
            . '</figure>';
    }
}
