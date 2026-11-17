<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\FileInterface;

/**
 * Keeps backend crops that live on a file reference.
 *
 * Extbase domain models wrap the core reference. Processing must use that
 * reference and copy its crop into the processing instructions. The original
 * file itself does not carry the crop.
 */
final class ImageReference
{
    public static function normalize(object $image): object
    {
        if (!is_callable([$image, 'getOriginalResource'])) {
            return $image;
        }

        $resource = $image->getOriginalResource();

        return is_object($resource) ? $resource : $image;
    }

    /**
     * @param array<string, mixed> $instructions
     * @return array<string, mixed>
     */
    public static function withReferenceCrop(object $image, array $instructions): array
    {
        if (array_key_exists('crop', $instructions)) {
            return $instructions;
        }

        $reference = self::normalize($image);
        if (!$reference instanceof FileInterface) {
            return $instructions;
        }
        if (!$reference->hasProperty('crop')) {
            return $instructions;
        }

        $crop = $reference->getProperty('crop');
        if (is_array($crop)) {
            $encoded = json_encode($crop);
            $crop = is_string($encoded) ? $encoded : '';
        }
        if (!is_string($crop) || $crop === '') {
            return $instructions;
        }

        $area = CropVariantCollection::create($crop)->getCropArea('default');
        if ($area->isEmpty() || $area->getWidth() <= 0.0 || $area->getHeight() <= 0.0) {
            return $instructions;
        }

        $instructions['crop'] = $area->makeAbsoluteBasedOnFile($reference);

        return $instructions;
    }
}
