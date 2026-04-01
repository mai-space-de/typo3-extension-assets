<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Service\ImageService;

final class ImageVariantService
{
    public function __construct(
        private readonly ImageService $imageService,
    ) {}

    /**
     * Process image variants for all breakpoints and formats.
     *
     * @param object $fileReference FAL file reference
     * @param array  $breakpoints   e.g. ['mobile' => 400, 'tablet' => 800, 'desktop' => 1200]
     * @return array<string, array<string, string|int>>
     */
    public function processVariants(object $fileReference, array $breakpoints): array
    {
        $result = [];
        $formats = ['avif', 'webp', 'jpeg'];

        foreach ($breakpoints as $bucket => $width) {
            $bucketData = ['width' => $width];
            foreach ($formats as $format) {
                try {
                    $processedImage = $this->imageService->applyProcessingInstructions(
                        $fileReference,
                        [
                            'width'    => $width,
                            'format'   => $format === 'jpeg' ? 'jpg' : $format,
                            'maxWidth' => $width,
                        ]
                    );
                    $bucketData[$format] = $this->imageService->getImageUri($processedImage, true);
                } catch (\Exception $e) {
                    // Format may not be supported — skip
                    $bucketData[$format] = '';
                }
            }
            $result[$bucket] = $bucketData;
        }

        return $result;
    }
}
