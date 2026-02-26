<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\EventListener;

use Maispace\MaispaceAssets\Event\BeforeImageProcessingEvent;

/**
 * Example event listener for BeforeImageProcessingEvent.
 *
 * This listener demonstrates how to modify image processing instructions before
 * TYPO3's ImageService processes the image. Common use cases include forcing a
 * specific output format (e.g., WebP or AVIF) without changing Fluid templates.
 *
 * HOW TO ACTIVATE IN YOUR SITE PACKAGE
 * =====================================
 * This listener is intentionally NOT registered by default. To activate it (or a
 * customised version of it), add the following to your site package's
 * Configuration/Services.yaml:
 *
 *   MyVendor\MySitePackage\EventListener\MyImageProcessingListener:
 *       tags:
 *           -   name: event.listener
 *               identifier: 'my-site-before-image-processing'
 *               event: Maispace\MaispaceAssets\Event\BeforeImageProcessingEvent
 *
 * AVAILABLE EVENT API
 * ====================
 * $event->getFile()                          — source File or FileReference
 * $event->getInstructions()                  — current processing instructions array
 * $event->setInstructions(array $i)          — replace all instructions at once
 * $event->getTargetFileExtension()           — get target format (e.g. "webp"), or null
 * $event->setTargetFileExtension(string $e)  — set target format (e.g. "webp", "avif")
 * $event->skip()                             — bypass processing, use original file
 * $event->isSkipped()                        — whether processing was skipped
 *
 * USE CASES
 * ==========
 * - Force WebP conversion for all raster images globally (no template changes)
 * - Force AVIF for supported MIME types when the graphics library allows it
 * - Skip processing for SVG files that should be served as-is
 * - Apply environment-specific instructions (e.g., sharper crops in production)
 *
 * @see \Maispace\MaispaceAssets\Event\BeforeImageProcessingEvent
 * @see \Maispace\MaispaceAssets\Service\ImageRenderingService::processImage()
 */
final class BeforeImageProcessingEventListener
{
    /**
     * MIME types that are suitable for conversion to modern formats.
     *
     * SVG and GIF (animated) are excluded — they should not be rasterised.
     *
     * @var array<string, true>
     */
    private const CONVERTIBLE_MIME_TYPES = [
        'image/jpeg' => true,
        'image/png'  => true,
        'image/bmp'  => true,
        'image/tiff' => true,
        'image/gif'  => true,
    ];

    public function __invoke(BeforeImageProcessingEvent $event): void
    {
        // -----------------------------------------------------------------
        // Example A: Force WebP output for all raster images, unless a target
        //            format is already explicitly requested.
        //            Equivalent to adding fileExtension="webp" everywhere in
        //            Fluid — but done globally here.
        //
        // if ($event->getTargetFileExtension() !== null) {
        //     return; // Respect explicitly configured format
        // }
        //
        // $mimeType = $event->getFile()->getMimeType();
        //
        // if (isset(self::CONVERTIBLE_MIME_TYPES[$mimeType])) {
        //     $event->setTargetFileExtension('webp');
        // }
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example B: Force AVIF for JPEG and PNG when your image processor
        //            (ImageMagick / Vips) supports it. Falls back gracefully
        //            when AVIF is not available because ImageService will
        //            simply produce the original format.
        //
        // if ($event->getTargetFileExtension() !== null) {
        //     return;
        // }
        //
        // $mimeType = $event->getFile()->getMimeType();
        //
        // if ($mimeType === 'image/jpeg' || $mimeType === 'image/png') {
        //     $event->setTargetFileExtension('avif');
        // }
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example C: Skip processing for SVG files.
        //            SVGs are vector graphics — resizing via raster pipeline
        //            is not meaningful; serve them directly.
        //
        // if ($event->getFile()->getMimeType() === 'image/svg+xml') {
        //     $event->skip();
        // }
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example D: Add maximum-width constraint for all images to prevent
        //            accidental upscaling of small source images.
        //
        // $instructions = $event->getInstructions();
        //
        // if (isset($instructions['width'])) {
        //     $numericWidth = (int)$instructions['width'];
        //     if ($numericWidth > 2560) {
        //         $instructions['width'] = '2560m';
        //         $event->setInstructions($instructions);
        //     }
        // }
        // -----------------------------------------------------------------
    }
}
