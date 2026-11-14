<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ImageMeasurement;

/**
 * Turns the measured histogram of an image slot into a {@see ImageMeasurementProfile}.
 */
class ImageMeasurementProfileResolver
{
    public function __construct(
        private readonly ImageMeasurementRepository $repository,
    ) {}

    public function resolve(string $slotKey, int $originalWidth = 0): ?ImageMeasurementProfile
    {
        return self::buildProfile($this->repository->getHistogram($slotKey), $originalWidth);
    }

    /**
     * Cheap change detection for cache invalidation; empty string when no profile exists yet.
     */
    public function signature(string $slotKey): string
    {
        return $this->resolve($slotKey)?->signature() ?? '';
    }

    /**
     * @param array<int, array<int, int>> $histogram viewport step => (css width => hits)
     */
    public static function buildProfile(array $histogram, int $originalWidth = 0): ?ImageMeasurementProfile
    {
        $steps = ImageMeasurementScale::viewportSteps();

        // 1. Width per viewport step that covers COVERAGE of all samples.
        $known = [];
        foreach ($steps as $step) {
            $widths = $histogram[$step] ?? [];
            $total = array_sum($widths);
            if ($total < ImageMeasurementScale::MIN_HITS) {
                continue;
            }
            ksort($widths);
            $target = (int) ceil($total * ImageMeasurementScale::COVERAGE);
            $cumulative = 0;
            foreach ($widths as $width => $hits) {
                $cumulative += $hits;
                if ($cumulative >= $target) {
                    $known[$step] = $width;
                    break;
                }
            }
        }

        if ($known === []) {
            return null;
        }

        // 2. Fill steps without enough samples conservatively with the wider neighbour.
        $values = [];
        foreach ($steps as $step) {
            if (isset($known[$step])) {
                $values[$step] = $known[$step];
                continue;
            }
            $lower = 0;
            $upper = 0;
            foreach ($known as $knownStep => $width) {
                if ($knownStep < $step) {
                    $lower = $width;
                } elseif ($knownStep > $step && $upper === 0) {
                    $upper = $width;
                }
            }
            $values[$step] = max($lower, $upper);
        }

        // 3. Merge consecutive steps with the same width into sizes entries.
        $groups = [];
        foreach ($values as $step => $width) {
            $last = array_key_last($groups);
            if ($last !== null && $groups[$last]['width'] === $width) {
                $groups[$last]['end'] = $step;
            } else {
                $groups[] = ['width' => $width, 'end' => $step];
            }
        }

        $sizesParts = [];
        $lastIndex = count($groups) - 1;
        foreach ($groups as $index => $group) {
            $sizesParts[] = $index === $lastIndex
                ? $group['width'] . 'px'
                : '(max-width: ' . $group['end'] . 'px) ' . $group['width'] . 'px';
        }

        // 4. srcset widths: every measured CSS width at all pixel ratios, on the ladder, capped to the original.
        $widths = [];
        foreach (array_unique(array_column($groups, 'width')) as $cssWidth) {
            foreach (ImageMeasurementScale::DPR_TIERS as $dpr) {
                $pixelWidth = ImageMeasurementScale::ceilToLadder($cssWidth * $dpr)
                    ?? ImageMeasurementScale::MAX_CSS_WIDTH;
                if ($originalWidth > 0) {
                    $pixelWidth = min($pixelWidth, $originalWidth);
                }
                $widths[$pixelWidth] = $pixelWidth;
            }
        }
        sort($widths);

        return new ImageMeasurementProfile(implode(', ', $sizesParts), array_values($widths));
    }
}
