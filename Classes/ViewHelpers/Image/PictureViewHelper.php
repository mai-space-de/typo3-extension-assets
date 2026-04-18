<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Image;

use Maispace\MaiAssets\Service\ImageVariantService;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Renders a <picture> element for art-directed responsive images.
 * Child <mai:picture.source> tags define breakpoint-specific <source> elements.
 *
 * Usage:
 *   <mai:picture image="{image}" alt="Description">
 *     <mai:picture.source media="(max-width: 767px)" srcset="{0: 400, 1: 800}" formats="{0: 'avif', 1: 'webp'}" />
 *     <mai:picture.source media="(min-width: 768px)" srcset="{0: 1200, 1: 1600}" formats="{0: 'avif', 1: 'webp'}" />
 *   </mai:picture>
 */
final class PictureViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function __construct(
        private readonly ImageVariantService $imageVariantService,
        private readonly ImageService $imageService,
    ) {
        parent::__construct();
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('image', 'object', 'FAL file reference or file object', true);
        $this->registerArgument('alt', 'string', 'Alt text for the fallback <img>', false, '');
        $this->registerArgument('width', 'int', 'Fallback image width', false, 0);
        $this->registerArgument('height', 'int', 'Fallback image height', false, 0);
        $this->registerArgument('lazyloading', 'bool', 'Add loading="lazy" to <img>', false, true);
        $this->registerArgument('fetchPriority', 'string', 'fetchpriority attribute (high/low/auto)', false, '');
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
        $lazyloading = (bool)$this->arguments['lazyloading'];
        $fetchPriority = (string)$this->arguments['fetchPriority'];
        $fileExtension = (string)$this->arguments['fileExtension'];
        $crossorigin = (string)$this->arguments['crossorigin'];
        $class = (string)$this->arguments['class'];

        // Expose file reference to child SourceViewHelpers via variable provider
        $variableProvider = $this->renderingContext->getVariableProvider();
        $variableProvider->add('__pictureFileReference', $image);

        // Render child content (source tags)
        $sources = $this->renderChildren();

        $variableProvider->remove('__pictureFileReference');

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

        if ($lazyloading) {
            $imgAttrs .= ' loading="lazy"';
        }
        if ($fetchPriority !== '') {
            $imgAttrs .= ' fetchpriority="' . htmlspecialchars($fetchPriority, ENT_QUOTES) . '"';
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
