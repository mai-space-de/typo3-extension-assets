<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\ViewHelpers\Picture;

use Closure;
use Maispace\MaispaceAssets\Service\ImageRenderingService;
use Maispace\MaispaceAssets\ViewHelpers\PictureViewHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;

/**
 * Render a single `<source>` tag inside a `<ma:picture>` element.
 *
 * Must be used as a direct child of `<ma:picture>`. The parent ViewHelper shares
 * the resolved image via `ViewHelperVariableContainer`; the source ViewHelper
 * inherits it unless an explicit `image` override is provided.
 *
 * The image is processed to the specified dimensions independently from the
 * parent's fallback `<img>` — each breakpoint gets its own optimised file.
 *
 * Global namespace: declared as "ma" in ext_localconf.php.
 *
 * Usage examples:
 *
 *   <ma:picture image="{imageRef}" alt="{alt}" width="1200">
 *       <!-- large viewport: process to 1200px wide -->
 *       <ma:picture.source media="(min-width: 980px)" width="1200" height="675" />
 *
 *       <!-- medium viewport: process to 800px wide -->
 *       <ma:picture.source media="(min-width: 768px)" width="800" height="450" />
 *
 *       <!-- small viewport: override with a different (portrait) image -->
 *       <ma:picture.source image="{portraitRef}" media="(max-width: 767px)" width="400" height="600" />
 *   </ma:picture>
 *
 *   <!-- Explicit MIME type (e.g. to force WebP) -->
 *   <ma:picture.source media="(min-width: 768px)" width="1200" type="image/webp" />
 *
 * @see \Maispace\MaispaceAssets\ViewHelpers\PictureViewHelper
 * @see \Maispace\MaispaceAssets\Service\ImageRenderingService
 */
final class SourceViewHelper extends AbstractViewHelper
{
    use CompileWithRenderStatic;

    /** Disable output escaping — this ViewHelper returns raw HTML. */
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument(
            'media',
            'string',
            'Media query that activates this source, e.g. "(min-width: 768px)". Omit to create a catch-all source.',
            false,
            null,
        );

        $this->registerArgument(
            'width',
            'string',
            'Target image width in TYPO3 notation: "800" (exact), "800c" (crop), "800m" (max). Defaults to the parent picture width when empty.',
            false,
            '',
        );

        $this->registerArgument(
            'height',
            'string',
            'Target image height in TYPO3 notation. Derived proportionally from width when empty.',
            false,
            '',
        );

        $this->registerArgument(
            'image',
            'mixed',
            'Override the image for this breakpoint. Inherits the parent <ma:picture> image when not set.',
            false,
            null,
        );

        $this->registerArgument(
            'type',
            'string',
            'MIME type for the <source> tag (e.g. "image/webp"). Auto-detected from the processed file extension when omitted.',
            false,
            null,
        );
    }

    public static function renderStatic(
        array $arguments,
        Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext,
    ): string {
        /** @var ImageRenderingService $service */
        $service      = GeneralUtility::makeInstance(ImageRenderingService::class);
        $varContainer = $renderingContext->getViewHelperVariableContainer();

        // Resolve the image: explicit override or inherited from parent PictureViewHelper.
        $imageArg = $arguments['image'] ?? null;
        if ($imageArg !== null) {
            $file = $service->resolveImage($imageArg);
        } else {
            /** @var \TYPO3\CMS\Core\Resource\File|\TYPO3\CMS\Core\Resource\FileReference|null $file */
            $file = $varContainer->get(PictureViewHelper::class, PictureViewHelper::VAR_FILE);
        }

        if ($file === null) {
            return '';
        }

        $processed = $service->processImage($file, (string)$arguments['width'], (string)$arguments['height']);

        return $service->renderSourceTag(
            $processed,
            $arguments['media'] ?? null,
            $arguments['type'] ?? null,
        );
    }
}
