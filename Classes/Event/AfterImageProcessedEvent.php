<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\Event;

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;

/**
 * Dispatched after an image has been processed (resized / converted) by TYPO3's ImageService.
 *
 * Event listeners can:
 *  - Inspect the processing result (URL, dimensions, format)
 *  - Replace the ProcessedFile with an alternative (e.g., one processed by a CDN)
 *  - Log processing metrics for performance profiling or auditing
 *  - Trigger cache warming for related image variants
 *
 * Note: This event is dispatched after the request-scoped cache has been populated.
 * Replacing the ProcessedFile via setProcessedFile() does NOT invalidate the cache
 * for this cycle — the replacement is returned directly to the caller.
 *
 * Registration example in your site package's Services.yaml:
 *
 *   MyVendor\MySitePackage\EventListener\MyImagePostProcessor:
 *       tags:
 *           -   name: event.listener
 *               identifier: 'my-site-after-image-processed'
 *               event: Maispace\MaispaceAssets\Event\AfterImageProcessedEvent
 *
 * @see \Maispace\MaispaceAssets\Service\ImageRenderingService::processImage()
 * @see \Maispace\MaispaceAssets\Event\BeforeImageProcessingEvent
 */
final class AfterImageProcessedEvent
{
    /**
     * @param File|FileReference   $sourceFile    The original source image
     * @param ProcessedFile        $processedFile The result from ImageService
     * @param array<string,mixed>  $instructions  The processing instructions that were applied
     */
    public function __construct(
        private readonly File|FileReference $sourceFile,
        private ProcessedFile $processedFile,
        private readonly array $instructions,
    ) {}

    /**
     * The original source image file or file reference.
     */
    public function getSourceFile(): File|FileReference
    {
        return $this->sourceFile;
    }

    /**
     * The processed image file returned by ImageService.
     */
    public function getProcessedFile(): ProcessedFile
    {
        return $this->processedFile;
    }

    /**
     * Replace the processed file that will be used for HTML rendering.
     *
     * The replacement must be a valid ProcessedFile (e.g., from an alternative
     * image processor or CDN-side transformation).
     */
    public function setProcessedFile(ProcessedFile $processedFile): void
    {
        $this->processedFile = $processedFile;
    }

    /**
     * The processing instructions that were applied to produce the processed file.
     *
     * Read-only — to change instructions, listen to BeforeImageProcessingEvent instead.
     *
     * @return array<string,mixed>
     */
    public function getInstructions(): array
    {
        return $this->instructions;
    }
}
