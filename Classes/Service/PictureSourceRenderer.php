<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use Maispace\MaiAssets\EarlyHints\EarlyHintCandidate;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;

/**
 * Builds <source> markup for mai:image.picture (shared by SourceViewHelper and default sources).
 */
class PictureSourceRenderer
{
    public function __construct(
        private readonly ImageVariantService $imageVariantService,
    ) {}

    /**
     * Standard responsive sources aligned with Hero art-direction breakpoints (perf-08).
     *
     * @return list<array{media: string, widths: list<int>}>
     */
    public function defaultSourceDefinitions(int $maxWidth = 1600): array
    {
        $desktopLarge = max(400, $maxWidth);
        $desktopSmall = (int) max(400, (int) round($desktopLarge * 0.75));

        return [
            ['media' => '(max-width: 767px)', 'widths' => [400, 800]],
            ['media' => '(min-width: 768px)', 'widths' => [$desktopSmall, $desktopLarge]],
        ];
    }

    /**
     * @param list<string> $formats
     */
    public function renderDefaultSources(
        object $fileReference,
        string $sizes,
        int $maxWidth,
        bool $isCritical,
        ?EarlyHintCandidateCollector $earlyHintCollector,
        array $formats = ['avif', 'webp'],
    ): string {
        $output = '';
        $registeredEarlyHint = false;

        foreach ($this->defaultSourceDefinitions($maxWidth) as $definition) {
            $output .= $this->renderSourcesForMedia(
                $fileReference,
                $definition['media'],
                $definition['widths'],
                $sizes,
                $formats,
                $isCritical,
                $earlyHintCollector,
                $registeredEarlyHint,
            );
        }

        return $output;
    }

    /**
     * @param list<int>          $widths
     * @param list<string>       $formats
     */
    public function renderSourcesForMedia(
        object $fileReference,
        string $media,
        array $widths,
        string $sizes,
        array $formats,
        bool $isCritical,
        ?EarlyHintCandidateCollector $earlyHintCollector,
        bool &$registeredEarlyHint = false,
    ): string {
        if ($widths === []) {
            return '';
        }

        $output = '';
        // First format with a srcset (the preferred one) gets the preload.
        $preloaded = false;

        foreach ($formats as $format) {
            $srcsetParts = [];
            $firstUrl = '';
            foreach ($widths as $width) {
                $breakpoints = ['w' . $width => (int) $width];
                $variants = $this->imageVariantService->processVariants($fileReference, $breakpoints);
                $url = $variants['w' . $width][$format] ?? '';
                if ($url !== '') {
                    $srcsetParts[] = htmlspecialchars($url, ENT_QUOTES) . ' ' . $width . 'w';
                    if ($firstUrl === '') {
                        $firstUrl = $url;
                    }
                }
            }

            if ($srcsetParts === []) {
                continue;
            }

            // One responsive preload per media group (art direction): the browser preloads
            // exactly the candidate it will pick from this srcset and these sizes.
            if ($isCritical && !$preloaded && $firstUrl !== ''
                && $earlyHintCollector instanceof EarlyHintCandidateCollector) {
                $earlyHintCollector->add(new EarlyHintCandidate(
                    href: $firstUrl,
                    rel: 'preload',
                    as: 'image',
                    type: $this->getMimeType((string) $format),
                    imageSrcset: html_entity_decode(implode(', ', $srcsetParts), ENT_QUOTES),
                    imageSizes: $sizes,
                    media: $media,
                ));
                $registeredEarlyHint = true;
                $preloaded = true;
            }

            $mimeType = $this->getMimeType((string) $format);
            $srcsetAttr = implode(', ', $srcsetParts);
            $sizesAttr = $sizes !== '' ? ' sizes="' . htmlspecialchars($sizes, ENT_QUOTES) . '"' : '';
            $fetchPriorityAttr = $isCritical ? ' fetchpriority="high"' : '';
            $mediaAttr = $media !== '' ? ' media="' . htmlspecialchars($media, ENT_QUOTES) . '"' : '';
            $output .= '<source'
                . $mediaAttr
                . ' srcset="' . $srcsetAttr . '"'
                . $sizesAttr
                . ' type="' . htmlspecialchars($mimeType, ENT_QUOTES) . '"'
                . $fetchPriorityAttr
                . '>' . "\n";
        }

        return $output;
    }

    private function getMimeType(string $format): string
    {
        return match ($format) {
            'avif'         => 'image/avif',
            'webp'         => 'image/webp',
            'jpg', 'jpeg'  => 'image/jpeg',
            'png'          => 'image/png',
            default        => 'image/' . $format,
        };
    }
}
