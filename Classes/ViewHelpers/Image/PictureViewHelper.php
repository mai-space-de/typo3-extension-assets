<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Image;

use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\Service\AssetCriticalityResolver;
use Maispace\MaiAssets\Service\ImageVariantService;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Renders a <picture> element for art-directed responsive images.
 * Child <mai:picture.source> tags define breakpoint-specific <source> elements.
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
        private readonly ImageVariantService $imageVariantService,
        private readonly ImageService $imageService,
        private readonly AssetCriticalityResolver $criticalityResolver,
        private readonly EarlyHintCandidateCollector $earlyHintCollector,
    ) {
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('image', 'object', 'FAL file reference or file object', true);
        $this->registerArgument('alt', 'string', 'Alt text for the fallback <img>', false, '');
        $this->registerArgument('width', 'int', 'Fallback image width', false, 0);
        $this->registerArgument('height', 'int', 'Fallback image height', false, 0);
        $this->registerArgument('critical', 'string', 'Criticality: auto|true|false', false, 'auto');
        $this->registerArgument('elementUid', 'int', 'Content element UID for auto-criticality detection', false, 0);
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
        $fileExtension = (string)$this->arguments['fileExtension'];
        $crossorigin = (string)$this->arguments['crossorigin'];
        $class = (string)$this->arguments['class'];

        $pageUid = (int)($this->renderingContext->getRequest()?->getAttribute('routing')?->getPageId() ?? 0);

        $isCritical = match ($critical) {
            'true'  => true,
            'false' => false,
            default => $elementUid > 0 && $pageUid > 0
                       && $this->criticalityResolver->isElementAboveFold($elementUid, $pageUid),
        };

        // Expose file reference and criticality to child SourceViewHelpers via variable provider
        $variableProvider = $this->renderingContext->getVariableProvider();
        $variableProvider->add('__pictureFileReference', $image);
        $variableProvider->add('__pictureIsCritical', $isCritical);
        $variableProvider->add('__pictureEarlyHintCollector', $this->earlyHintCollector);

        // Render child content (source tags)
        $sources = $this->renderChildren();

        $variableProvider->remove('__pictureFileReference');
        $variableProvider->remove('__pictureIsCritical');
        $variableProvider->remove('__pictureEarlyHintCollector');

        // Generate fallback <img>
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

        try {
            $processedImage = $this->imageService->applyProcessingInstructions($image, $processingInstructions);
            $imgSrc = $this->imageService->getImageUri($processedImage, true);
        } catch (\Exception) {
            $imgSrc = '';
        }

        $imgAttrs = 'src="' . htmlspecialchars($imgSrc, ENT_QUOTES) . '"'
            . ' alt="' . htmlspecialchars($alt, ENT_QUOTES) . '"';

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
}
