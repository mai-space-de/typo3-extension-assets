<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ImageMeasurement;

/**
 * Result of the measurement for one image slot: the {@code sizes} attribute
 * describing the real layout width per viewport, and the srcset widths needed
 * for it (CSS widths × device pixel ratios).
 */
final readonly class ImageMeasurementProfile
{
    /**
     * @param list<int> $widths ascending pixel widths for the srcset
     */
    public function __construct(
        public string $sizes,
        public array $widths,
    ) {}

    /**
     * @return array<string, int> breakpoint map as understood by ImageVariantService
     */
    public function breakpoints(): array
    {
        $breakpoints = [];
        foreach ($this->widths as $width) {
            $breakpoints['w' . $width] = $width;
        }

        return $breakpoints;
    }

    public function signature(): string
    {
        return md5($this->sizes . '|' . implode(',', $this->widths));
    }
}
