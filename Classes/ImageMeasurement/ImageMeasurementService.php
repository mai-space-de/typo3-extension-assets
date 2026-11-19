<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ImageMeasurement;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Security\ImageMeasureTokenService;

/**
 * Entry point for ViewHelpers: decides whether an image is measured and
 * returns the learned profile, if there is one already.
 */
class ImageMeasurementService
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ImageMeasurementProfileResolver $profileResolver,
        private readonly ImageMeasureTokenService $tokenService,
    ) {}

    public function prepare(object $image, int $elementUid, int $pageUid, string $slot = ''): ?ImageMeasurementContext
    {
        if (!$this->extensionConfiguration->isEnableImageMeasurement() || $pageUid <= 0) {
            return null;
        }

        // Without a content element or layout slot there is no defined place: the same file
        // in header, footer and content would mix its widths into one profile.
        if ($elementUid <= 0 && trim($slot) === '') {
            return null;
        }

        $key = ImageMeasurementKey::fromImage($image, $elementUid, $slot);
        if ($key === null) {
            return null;
        }

        $token = $this->tokenService->generate($pageUid, $key);
        if ($token === '') {
            return null;
        }

        try {
            $profile = $this->profileResolver->resolve($key, ImageMeasurementKey::originalWidth($image));
        } catch (\Throwable) {
            // Missing table (database not yet compared) must never break rendering.
            $profile = null;
        }

        return new ImageMeasurementContext($key, $token, $profile);
    }
}
