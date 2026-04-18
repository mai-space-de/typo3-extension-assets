<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use Maispace\MaiAssets\Event\AfterImageProcessedEvent;
use Maispace\MaiAssets\Event\BeforeImageProcessingEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Extbase\Service\ImageService;

final class ImageVariantService
{
    public function __construct(
        private readonly ImageService $imageService,
        private readonly EventDispatcherInterface $eventDispatcher,
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
        $beforeEvent = new BeforeImageProcessingEvent($fileReference, $breakpoints);
        $this->eventDispatcher->dispatch($beforeEvent);
        if ($beforeEvent->isCancelled()) {
            return [];
        }
        $breakpoints = $beforeEvent->getBreakpoints();

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

        $afterEvent = new AfterImageProcessedEvent($result, $fileReference);
        $this->eventDispatcher->dispatch($afterEvent);

        return $afterEvent->getVariants();
    }
}
