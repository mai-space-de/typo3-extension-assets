<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\Event;

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;

/**
 * Dispatched before an image file is processed (resized / converted) by TYPO3's ImageService.
 *
 * Event listeners can:
 *  - Inspect the source file and requested processing dimensions
 *  - Override the target file extension (e.g., force "webp" or "avif")
 *  - Modify processing instructions (width, height, crop settings)
 *  - Skip processing entirely by calling skip() — the original file is then used
 *    directly without resizing or format conversion
 *
 * The event is dispatched once per unique file+dimensions combination per request.
 * The request-scoped cache in ImageRenderingService avoids duplicate dispatches for
 * the same image when it appears in multiple ViewHelper invocations.
 *
 * Registration example in your site package's Services.yaml:
 *
 *   MyVendor\MySitePackage\EventListener\MyImageProcessingListener:
 *       tags:
 *           -   name: event.listener
 *               identifier: 'my-site-before-image-processing'
 *               event: Maispace\MaispaceAssets\Event\BeforeImageProcessingEvent
 *
 * @see \Maispace\MaispaceAssets\Service\ImageRenderingService::processImage()
 * @see \Maispace\MaispaceAssets\Event\AfterImageProcessedEvent
 */
final class BeforeImageProcessingEvent
{
    private bool $skipped = false;

    /**
     * @param File|FileReference $file            The source image file
     * @param array<string,mixed> $instructions   Processing instructions array passed to ImageService
     *                                            (keys: 'width', 'height', 'fileExtension', etc.)
     */
    public function __construct(
        private readonly File|FileReference $file,
        private array $instructions,
    ) {}

    /**
     * The source image file or file reference being processed.
     */
    public function getFile(): File|FileReference
    {
        return $this->file;
    }

    /**
     * The current processing instructions that will be passed to ImageService.
     *
     * Common keys: 'width', 'height', 'fileExtension', 'crop'.
     *
     * @return array<string,mixed>
     */
    public function getInstructions(): array
    {
        return $this->instructions;
    }

    /**
     * Replace the full set of processing instructions.
     *
     * @param array<string,mixed> $instructions
     */
    public function setInstructions(array $instructions): void
    {
        $this->instructions = $instructions;
    }

    /**
     * Convenience: get the requested target file extension (e.g., "webp", "avif").
     * Returns null when not explicitly set.
     */
    public function getTargetFileExtension(): ?string
    {
        $ext = $this->instructions['fileExtension'] ?? null;
        return is_string($ext) && $ext !== '' ? $ext : null;
    }

    /**
     * Convenience: set the target file extension, overriding any existing value.
     *
     * @param string $extension File extension without leading dot, e.g. "webp", "avif", "jpg"
     */
    public function setTargetFileExtension(string $extension): void
    {
        $this->instructions['fileExtension'] = $extension;
    }

    /**
     * Mark the image as skipped — ImageRenderingService will return the original
     * (unprocessed) file wrapped in a ProcessedFile instead of calling ImageService.
     *
     * Use this to bypass processing entirely for specific file types or conditions.
     */
    public function skip(): void
    {
        $this->skipped = true;
    }

    /**
     * Whether skip() was called by a listener.
     */
    public function isSkipped(): bool
    {
        return $this->skipped;
    }
}
