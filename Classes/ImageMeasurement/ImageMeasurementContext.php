<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ImageMeasurement;

/**
 * Everything a ViewHelper needs to render one measured image.
 */
final readonly class ImageMeasurementContext
{
    public function __construct(
        public string $key,
        public string $token,
        public ?ImageMeasurementProfile $profile,
    ) {}

    /**
     * Attribute (with leading space) read by the beacon script.
     */
    public function attribute(): string
    {
        return ' data-mai-m="' . htmlspecialchars($this->key . ':' . $this->token, ENT_QUOTES) . '"';
    }
}
