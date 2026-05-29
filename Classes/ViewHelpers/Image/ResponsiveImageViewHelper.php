<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Image;

use Maispace\MaiAssets\EarlyHints\EarlyHintCandidate;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\Service\AssetCriticalityResolver;
use Maispace\MaiAssets\Service\ImageVariantService;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class ResponsiveImageViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function __construct(
        private readonly ImageVariantService $imageVariantService,
        private readonly AssetCriticalityResolver $criticalityResolver,
        private readonly EarlyHintCandidateCollector $earlyHintCollector,
    ) {
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('image', 'object', 'FAL file reference', true);
        $this->registerArgument('breakpoints', 'array', 'Breakpoints with widths', true);
        $this->registerArgument('sizes', 'string', 'Sizes attribute value', true);
        $this->registerArgument('critical', 'string', 'Criticality: auto|true|false', false, 'auto');
        $this->registerArgument('elementUid', 'int', 'Content element UID for auto-criticality detection', false, 0);
        $this->registerArgument('alt', 'string', 'Alt text', false, '');
        $this->registerArgument('class', 'string', 'CSS class', false, '');
    }

    public function render(): string
    {
        $image = $this->arguments['image'];
        $breakpoints = (array)$this->arguments['breakpoints'];
        $sizes = (string)$this->arguments['sizes'];
        $critical = (string)$this->arguments['critical'];
        $elementUid = (int)$this->arguments['elementUid'];
        $alt = (string)$this->arguments['alt'];
        $class = (string)$this->arguments['class'];

        $pageUid = (int)($this->renderingContext->getRequest()?->getAttribute('routing')?->getPageId() ?? 0);

        $isCritical = match ($critical) {
            'true'  => true,
            'false' => false,
            default => $elementUid > 0 && $pageUid > 0
                       && $this->criticalityResolver->isElementAboveFold($elementUid, $pageUid),
        };

        $variants = $this->imageVariantService->processVariants($image, $breakpoints);

        if ($isCritical && isset($variants['desktop']['avif']) && $variants['desktop']['avif'] !== '') {
            $avifUrl = $variants['desktop']['avif'];
            $this->assetCollector->addLink(
                'mai_assets_avif_preload_' . md5($avifUrl),
                [
                    'rel'  => 'preload',
                    'href' => $avifUrl,
                    'as'   => 'image',
                    'type' => 'image/avif',
                ]
            );
            $this->earlyHintCollector->add(new EarlyHintCandidate(
                href: $avifUrl,
                rel: 'preload',
                as: 'image',
            ));
        }

        $loadingAttr = $isCritical ? 'eager' : 'lazy';
        $fetchPriority = $isCritical ? ' fetchpriority="high"' : '';
        $decoding = $isCritical ? 'sync' : 'async';
        $classAttr = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"' : '';
        $altAttr = ' alt="' . htmlspecialchars($alt, ENT_QUOTES) . '"';

        $html = '<picture>';

        $avifSrcset = $this->buildSrcset($variants, 'avif');
        if ($avifSrcset !== '') {
            $html .= '<source type="image/avif" srcset="' . $avifSrcset . '" sizes="' . htmlspecialchars($sizes, ENT_QUOTES) . '">';
        }

        $webpSrcset = $this->buildSrcset($variants, 'webp');
        if ($webpSrcset !== '') {
            $html .= '<source type="image/webp" srcset="' . $webpSrcset . '" sizes="' . htmlspecialchars($sizes, ENT_QUOTES) . '">';
        }

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
