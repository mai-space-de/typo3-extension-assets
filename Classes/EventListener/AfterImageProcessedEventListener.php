<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\EventListener;

use Maispace\MaispaceAssets\Event\AfterImageProcessedEvent;

/**
 * Example event listener for AfterImageProcessedEvent.
 *
 * This listener demonstrates how to inspect or replace the ProcessedFile after
 * TYPO3's ImageService has finished processing an image. Common use cases include
 * logging, CDN integration, or post-processing via an external service.
 *
 * HOW TO ACTIVATE IN YOUR SITE PACKAGE
 * =====================================
 * This listener is intentionally NOT registered by default. To activate it (or a
 * customised version of it), add the following to your site package's
 * Configuration/Services.yaml:
 *
 *   MyVendor\MySitePackage\EventListener\MyImagePostProcessor:
 *       tags:
 *           -   name: event.listener
 *               identifier: 'my-site-after-image-processed'
 *               event: Maispace\MaispaceAssets\Event\AfterImageProcessedEvent
 *
 * AVAILABLE EVENT API
 * ====================
 * $event->getSourceFile()                     — original File or FileReference
 * $event->getProcessedFile()                  — the ProcessedFile from ImageService
 * $event->setProcessedFile(ProcessedFile $pf) — replace with an alternative ProcessedFile
 * $event->getInstructions()                   — the processing instructions that were applied
 *
 * USE CASES
 * ==========
 * - Log image processing metrics (dimensions, format, file size) for auditing
 * - Trigger CDN cache-warming or purging for the new processed URL
 * - Replace the ProcessedFile with one produced by an external image API (e.g., Cloudinary)
 * - Collect statistics for a performance monitoring dashboard
 *
 * @see AfterImageProcessedEvent
 * @see \Maispace\MaispaceAssets\Service\ImageRenderingService::processImage()
 */
final class AfterImageProcessedEventListener
{
    public function __invoke(AfterImageProcessedEvent $event): void
    {
        // -----------------------------------------------------------------
        // Example A: Log processing result for performance auditing.
        //
        // $processed     = $event->getProcessedFile();
        // $source        = $event->getSourceFile();
        // $instructions  = $event->getInstructions();
        //
        // error_log(sprintf(
        //     '[maispace_assets] Image processed: %s → %s (width: %s, height: %s, format: %s)',
        //     $source->getIdentifier(),
        //     $processed->getIdentifier(),
        //     $instructions['width'] ?? 'original',
        //     $instructions['height'] ?? 'original',
        //     $instructions['fileExtension'] ?? 'original',
        // ));
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example B: Trigger a CDN cache-warming request for the processed URL.
        //            The URL is retrieved from the ProcessedFile's public URL.
        //
        // $processed = $event->getProcessedFile();
        // $url       = $processed->getPublicUrl();
        //
        // if ($url !== null) {
        //     // Fire-and-forget CDN warm-up (use a queue in production)
        //     $this->cdnService->warmUrl($url);
        // }
        // -----------------------------------------------------------------

        // -----------------------------------------------------------------
        // Example C: Replace the ProcessedFile with one from an external CDN
        //            image transformation service. Only do this for production.
        //
        // if (!GeneralUtility::getApplicationContext()->isProduction()) {
        //     return;
        // }
        //
        // $processed = $event->getProcessedFile();
        // $cdnFile   = $this->cdnImageService->getProcessedFile($processed);
        //
        // if ($cdnFile !== null) {
        //     $event->setProcessedFile($cdnFile);
        // }
        // -----------------------------------------------------------------
    }
}
