<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Image\Picture;

use Maispace\MaiAssets\Service\ImageVariantService;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Renders a <source> element inside a <mai:picture> container.
 * Must be used as a direct child of PictureViewHelper.
 */
final class SourceViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function __construct(
        private readonly ImageVariantService $imageVariantService,
    ) {
        parent::__construct();
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('media', 'string', 'Media query for this source (required)', true);
        $this->registerArgument('srcset', 'array', 'Array of widths to generate (e.g. [400, 800, 1200])', false, []);
        $this->registerArgument('sizes', 'string', 'sizes attribute', false, '');
        $this->registerArgument('formats', 'array', 'Image formats to generate, e.g. ["avif","webp"]', false, ['avif', 'webp']);
        $this->registerArgument('quality', 'int', 'Image quality', false, 85);
        $this->registerArgument('width', 'int', 'Fallback width', false, 0);
        $this->registerArgument('height', 'int', 'Fallback height', false, 0);
    }

    public function render(): string
    {
        $templateVariableContainer = $this->renderingContext->getVariableProvider();

        // Only render inside a PictureViewHelper context
        if (!$templateVariableContainer->exists('__pictureFileReference')) {
            return '';
        }

        $fileReference = $templateVariableContainer->get('__pictureFileReference');
        $media = (string)$this->arguments['media'];
        $widths = (array)$this->arguments['srcset'];
        $sizes = (string)$this->arguments['sizes'];
        $formats = (array)$this->arguments['formats'];
        $quality = (int)$this->arguments['quality'];

        if ($widths === []) {
            return '';
        }

        $output = '';

        foreach ($formats as $format) {
            $srcsetParts = [];
            foreach ($widths as $width) {
                $breakpoints = ['w' . $width => (int)$width];
                $variants = $this->imageVariantService->processVariants($fileReference, $breakpoints);
                $url = $variants['w' . $width][$format] ?? '';
                if ($url !== '') {
                    $srcsetParts[] = htmlspecialchars($url, ENT_QUOTES) . ' ' . $width . 'w';
                }
            }

            if ($srcsetParts === []) {
                continue;
            }

            $mimeType = $this->getMimeType((string)$format);
            $srcsetAttr = implode(', ', $srcsetParts);
            $sizesAttr = $sizes !== '' ? ' sizes="' . htmlspecialchars($sizes, ENT_QUOTES) . '"' : '';
            $output .= '<source'
                . ' media="' . htmlspecialchars($media, ENT_QUOTES) . '"'
                . ' srcset="' . $srcsetAttr . '"'
                . $sizesAttr
                . ' type="' . htmlspecialchars($mimeType, ENT_QUOTES) . '"'
                . '>' . "\n";
        }

        return $output;
    }

    private function getMimeType(string $format): string
    {
        return match ($format) {
            'avif'  => 'image/avif',
            'webp'  => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'   => 'image/png',
            default => 'image/' . $format,
        };
    }
}
