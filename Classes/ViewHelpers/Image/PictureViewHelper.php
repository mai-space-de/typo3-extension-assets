<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Image;

use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ViewHelperNode;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\Service\AssetCriticalityResolver;
use Maispace\MaiAssets\Service\PictureSourceRenderer;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Renders a <picture> element for art-directed responsive images.
 * Child <mai:picture.source> tags define breakpoint-specific <source> elements.
 * When no children are present, Hero-aligned AVIF/WebP srcsets are emitted automatically.
 *
 * Usage:
 *   <mai:picture image="{image}" alt="Description" elementUid="{data.uid}">
 *     <mai:picture.source media="(max-width: 767px)" srcset="{0: 400, 1: 800}" formats="{0: 'avif', 1: 'webp'}" />
 *     <mai:picture.source media="(min-width: 768px)" srcset="{0: 1200, 1: 1600}" formats="{0: 'avif', 1: 'webp'}" />
 *   </mai:picture>
 */
class PictureViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function __construct(
        private readonly PictureSourceRenderer $pictureSourceRenderer,
        private readonly ImageService $imageService,
        private readonly AssetCriticalityResolver $criticalityResolver,
        private readonly EarlyHintCandidateCollector $earlyHintCollector,
    ) {
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('image', 'object', 'FAL file reference or file object', true);
        $this->registerArgument('alt', 'string', 'Alt text for the fallback <img>', false, '');
        $this->registerArgument('width', 'int', 'Fallback image width (also scales default srcset)', false, 0);
        $this->registerArgument('height', 'int', 'Fallback image height', false, 0);
        $this->registerArgument('critical', 'string', 'Criticality: auto|true|false', false, 'auto');
        $this->registerArgument('elementUid', 'int', 'Content element UID for auto-criticality detection', false, 0);
        $this->registerArgument('sizes', 'string', 'sizes attribute for default <source> elements', false, '100vw');
        $this->registerArgument('quality', 'int', 'Fallback image quality', false, 85);
        $this->registerArgument('fileExtension', 'string', 'Fallback image format (e.g. jpg)', false, '');
        $this->registerArgument('crossorigin', 'string', 'crossorigin attribute', false, '');
        $this->registerArgument('class', 'string', 'CSS class on <img>', false, '');
    }

    public function render(): string
    {
        $image = $this->arguments['image'];
        $alt = (string)$this->arguments['alt'];
        $width = (int)$this->arguments['width'];
        $height = (int)$this->arguments['height'];
        $critical = (string)$this->arguments['critical'];
        $elementUid = (int)$this->arguments['elementUid'];
        $sizes = (string)$this->arguments['sizes'];
        $fileExtension = (string)$this->arguments['fileExtension'];
        $crossorigin = (string)$this->arguments['crossorigin'];
        $class = (string)$this->arguments['class'];

        $request = $this->renderingContext->hasAttribute(ServerRequestInterface::class)
            ? $this->renderingContext->getAttribute(ServerRequestInterface::class)
            : null;
        $pageUid = (int)($request?->getAttribute('routing')?->getPageId() ?? 0);

        $isCritical = match ($critical) {
            'true'  => true,
            'false' => false,
            default => $elementUid > 0 && $pageUid > 0
                       && $this->criticalityResolver->isElementAboveFold($elementUid, $pageUid),
        };

        $viewHelperVariableContainer = $this->renderingContext->getViewHelperVariableContainer();
        $viewHelperVariableContainer->add(self::class, 'fileReference', $image);
        $viewHelperVariableContainer->add(self::class, 'isCritical', $isCritical);
        $viewHelperVariableContainer->add(self::class, 'earlyHintCollector', $this->earlyHintCollector);

        $sources = $this->renderChildSources();

        if ($sources === '') {
            $maxWidth = $width > 0 ? $width : 1600;
            $sources = $this->pictureSourceRenderer->renderDefaultSources(
                $image,
                $sizes,
                $maxWidth,
                $isCritical,
                $this->earlyHintCollector,
            );
        }

        $viewHelperVariableContainer->remove(self::class, 'fileReference');
        $viewHelperVariableContainer->remove(self::class, 'isCritical');
        $viewHelperVariableContainer->remove(self::class, 'earlyHintCollector');

        $processingInstructions = [];
        if ($width > 0) {
            $processingInstructions['width'] = $width;
        }
        if ($height > 0) {
            $processingInstructions['height'] = $height;
        }
        if ($fileExtension !== '') {
            $processingInstructions['fileExtension'] = $fileExtension;
        }

        $imgWidth = $width;
        $imgHeight = $height;

        try {
            $processedImage = $this->imageService->applyProcessingInstructions($image, $processingInstructions);
            $imgSrc = $this->imageService->getImageUri($processedImage, true);
            if ($imgWidth <= 0) {
                $imgWidth = (int)($processedImage->getProperty('width') ?? 0);
            }
            if ($imgHeight <= 0) {
                $imgHeight = (int)($processedImage->getProperty('height') ?? 0);
            }
        } catch (\Exception) {
            $imgSrc = '';
        }

        $imgAttrs = 'src="' . htmlspecialchars($imgSrc, ENT_QUOTES) . '"'
            . ' alt="' . htmlspecialchars($alt, ENT_QUOTES) . '"';

        if ($imgWidth > 0) {
            $imgAttrs .= ' width="' . $imgWidth . '"';
        }
        if ($imgHeight > 0) {
            $imgAttrs .= ' height="' . $imgHeight . '"';
        }

        if ($isCritical) {
            $imgAttrs .= ' loading="eager" fetchpriority="high" decoding="sync"';
        } else {
            $imgAttrs .= ' loading="lazy"';
        }

        if ($crossorigin !== '') {
            $imgAttrs .= ' crossorigin="' . htmlspecialchars($crossorigin, ENT_QUOTES) . '"';
        }
        if ($class !== '') {
            $imgAttrs .= ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"';
        }

        return '<picture>' . "\n"
            . $sources
            . '<img ' . $imgAttrs . '>' . "\n"
            . '</picture>';
    }

    /**
     * Renders nested <mai:image.picture.source> children when present in the Fluid tree.
     */
    protected function renderChildSources(): string
    {
        if (!$this->viewHelperNode instanceof ViewHelperNode) {
            return '';
        }

        return trim((string)$this->renderChildren());
    }
}
