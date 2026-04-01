<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Image;

use Maispace\MaiAssets\Service\ImageVariantService;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

final class ResponsiveImageViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function __construct(
        private readonly ImageVariantService $imageVariantService,
        private readonly AssetCollector $assetCollector,
    ) {
        parent::__construct();
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('image', 'object', 'FAL file reference', true);
        $this->registerArgument('breakpoints', 'array', 'Breakpoints with widths', true);
        $this->registerArgument('sizes', 'string', 'Sizes attribute value', true);
        $this->registerArgument('isCritical', 'bool', 'Whether the image is critical', false, false);
        $this->registerArgument('alt', 'string', 'Alt text', false, '');
        $this->registerArgument('class', 'string', 'CSS class', false, '');
    }

    public function render(): string
    {
        $image = $this->arguments['image'];
        $breakpoints = (array)$this->arguments['breakpoints'];
        $sizes = (string)$this->arguments['sizes'];
        $isCritical = (bool)$this->arguments['isCritical'];
        $alt = (string)$this->arguments['alt'];
        $class = (string)$this->arguments['class'];

        $variants = $this->imageVariantService->processVariants($image, $breakpoints);

        // Register AVIF preload for critical images
        if ($isCritical && isset($variants['desktop']['avif']) && $variants['desktop']['avif'] !== '') {
            $this->assetCollector->addLink(
                'mai_assets_avif_preload_' . md5($variants['desktop']['avif']),
                [
                    'rel'  => 'preload',
                    'href' => $variants['desktop']['avif'],
                    'as'   => 'image',
                    'type' => 'image/avif',
                ]
            );
        }

        $loadingAttr = $isCritical ? 'eager' : 'lazy';
        $fetchPriority = $isCritical ? ' fetchpriority="high"' : '';
        $decoding = $isCritical ? 'sync' : 'async';
        $classAttr = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"' : '';
        $altAttr = ' alt="' . htmlspecialchars($alt, ENT_QUOTES) . '"';

        $html = '<picture>';

        // AVIF sources
        $avifSrcset = $this->buildSrcset($variants, 'avif');
        if ($avifSrcset !== '') {
            $html .= '<source type="image/avif" srcset="' . $avifSrcset . '" sizes="' . htmlspecialchars($sizes, ENT_QUOTES) . '">';
        }

        // WebP sources
        $webpSrcset = $this->buildSrcset($variants, 'webp');
        if ($webpSrcset !== '') {
            $html .= '<source type="image/webp" srcset="' . $webpSrcset . '" sizes="' . htmlspecialchars($sizes, ENT_QUOTES) . '">';
        }

        // JPEG fallback img
        $jpegSrcset = $this->buildSrcset($variants, 'jpeg');
        $fallbackSrc = $variants['desktop']['jpeg'] ?? ($variants[array_key_last($variants)]['jpeg'] ?? '');

        $html .= '<img'
            . ' src="' . htmlspecialchars($fallbackSrc, ENT_QUOTES) . '"'
            . ($jpegSrcset !== '' ? ' srcset="' . $jpegSrcset . '"' : '')
            . ' sizes="' . htmlspecialchars($sizes, ENT_QUOTES) . '"'
            . $altAttr
            . $classAttr
            . ' loading="' . $loadingAttr . '"'
            . $fetchPriority
            . ' decoding="' . $decoding . '"'
            . '>';

        $html .= '</picture>';

        return $html;
    }

    private function buildSrcset(array $variants, string $format): string
    {
        $parts = [];
        foreach ($variants as $bucket => $data) {
            if (!empty($data[$format]) && !empty($data['width'])) {
                $parts[] = htmlspecialchars($data[$format], ENT_QUOTES) . ' ' . (int)$data['width'] . 'w';
            }
        }
        return implode(', ', $parts);
    }
}
