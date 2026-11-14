<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ImageMeasurement;

/**
 * Fixed scales of the image measurement feature.
 *
 * Every value that reaches the image processing pipeline originates from one
 * of these whitelists. A client can therefore never request an arbitrary
 * width: reported widths are rounded UP to {@see self::WIDTH_LADDER} and
 * reported viewports are folded into {@see self::VIEWPORT_STEPS}. This bounds
 * the number of processed variants per image, no matter what is reported.
 */
final class ImageMeasurementScale
{
    /** CSS pixel widths an image slot can be rounded up to. */
    public const array WIDTH_LADDER = [
        160, 240, 320, 360, 400, 480, 560, 640, 720, 800,
        960, 1080, 1200, 1280, 1440, 1600, 1920, 2560, 3200, 3840,
    ];

    public const int MAX_CSS_WIDTH = 3840;

    /** Device pixel ratios a srcset is built for. */
    public const array DPR_TIERS = [1, 2];

    public const int VIEWPORT_MIN_STEP = 320;
    public const int VIEWPORT_MAX_STEP = 2560;
    public const int VIEWPORT_STEP_SIZE = 160;
    /** Marker step for every viewport wider than {@see self::VIEWPORT_MAX_STEP}. */
    public const int VIEWPORT_OPEN_STEP = 2720;

    public const int MIN_VIEWPORT = 200;
    public const int MAX_VIEWPORT = 10000;

    /** Samples a viewport step needs before its measurement is trusted. */
    public const int MIN_HITS = 3;
    /** A viewport step stops accepting samples after this many hits (data is considered converged). */
    public const int MAX_HITS_PER_STEP = 300;
    /** Distinct width rows stored per slot and viewport step. */
    public const int MAX_ROWS_PER_STEP = 8;
    /** Share of samples a measured width has to cover. */
    public const float COVERAGE = 0.9;

    /**
     * @return list<int>
     */
    public static function viewportSteps(): array
    {
        $steps = [];
        for ($step = self::VIEWPORT_MIN_STEP; $step <= self::VIEWPORT_MAX_STEP; $step += self::VIEWPORT_STEP_SIZE) {
            $steps[] = $step;
        }
        $steps[] = self::VIEWPORT_OPEN_STEP;

        return $steps;
    }

    public static function viewportStep(int $viewportWidth): int
    {
        if ($viewportWidth > self::VIEWPORT_MAX_STEP) {
            return self::VIEWPORT_OPEN_STEP;
        }
        $step = (int) (ceil($viewportWidth / self::VIEWPORT_STEP_SIZE) * self::VIEWPORT_STEP_SIZE);

        return max(self::VIEWPORT_MIN_STEP, $step);
    }

    /**
     * Rounds a CSS width up to the next ladder rung; null when it is out of range.
     */
    public static function ceilToLadder(int $cssWidth): ?int
    {
        if ($cssWidth < 1 || $cssWidth > self::MAX_CSS_WIDTH) {
            return null;
        }
        foreach (self::WIDTH_LADDER as $rung) {
            if ($rung >= $cssWidth) {
                return $rung;
            }
        }

        return null;
    }
}
