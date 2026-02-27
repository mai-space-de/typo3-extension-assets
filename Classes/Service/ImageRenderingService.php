<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\Service;

use Maispace\MaispaceAssets\Event\AfterImageProcessedEvent;
use Maispace\MaispaceAssets\Event\BeforeImageProcessingEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Service\ImageService;

/**
 * Resolves, processes, and renders images for the mai:image, mai:picture, and
 * mai:picture.source ViewHelpers.
 *
 * Image input resolution
 * ======================
 * The `image` argument accepted by the ViewHelpers can be any of:
 *  - int/numeric string  → sys_file_reference UID → FAL FileReference
 *  - File object         → used directly
 *  - FileReference object → used directly
 *  - string path         → EXT: notation or public-relative path → FAL File
 *
 * Image processing
 * ================
 * All resizing and format conversion is delegated to TYPO3's `ImageService`, which
 * honours the TYPO3 image processing configuration (GraphicsMagick / ImageMagick / GD)
 * and supports WebP conversion when configured in Install Tool.
 *
 * Width/height strings follow TYPO3 notation:
 *  - `800`   → exact pixel width
 *  - `800c`  → crop to exact width
 *  - `800m`  → maximum width (proportional scale)
 *
 * Format alternatives (for <picture> source sets)
 * ================================================
 * `processImageAlternatives()` accepts a list of target formats and returns one
 * ProcessedFile per format, in the order given. This powers the automatic
 * `<source type="image/avif">` / `<source type="image/webp">` / `<img>` pattern
 * without requiring template changes.
 *
 * TypoScript-driven alternative formats are configurable via:
 *   plugin.tx_maispace_assets.image.alternativeFormats = avif, webp
 *
 * Lazy loading
 * ============
 * - `lazyloading="true"`            → adds `loading="lazy"` attribute on `<img>`
 * - `lazyloadWithClass="classname"` → adds `loading="lazy"` AND a CSS class
 * Both can be combined; classes are merged.
 *
 * Preload
 * =======
 * When `preload="true"`, a `<link rel="preload" as="image">` tag is added to `<head>`
 * via PageRenderer. An optional `media` attribute scopes the hint to a breakpoint.
 *
 * Events
 * ======
 * - BeforeImageProcessingEvent — dispatched before each processImage() call;
 *   listeners can modify processing instructions, force a target format, or skip.
 * - AfterImageProcessedEvent  — dispatched after each processImage() call;
 *   listeners can inspect or replace the resulting ProcessedFile.
 */
final class ImageRenderingService implements SingletonInterface
{
    /** @var array<string, ProcessedFile> Static cache to avoid reprocessing the same image twice per request */
    private static array $processedFileCache = [];

    public function __construct(
        private readonly ImageService $imageService,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    // -------------------------------------------------------------------------
    // Image resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve any supported image input to a FAL File or FileReference.
     *
     * @param mixed $image int UID, File, FileReference, or string path
     * @return File|FileReference|null Returns null and logs a warning on failure
     */
    public function resolveImage(mixed $image): File|FileReference|null
    {
        if ($image instanceof File || $image instanceof FileReference) {
            return $image;
        }

        /** @var ResourceFactory $resourceFactory */
        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);

        // Integer or numeric string → sys_file_reference UID
        if (is_int($image) || (is_string($image) && ctype_digit($image))) {
            try {
                return $resourceFactory->getFileReferenceObject((int)$image);
            } catch (\Exception $e) {
                $this->logger->warning(
                    'maispace_assets: Could not resolve FileReference with UID ' . $image . ': ' . $e->getMessage(),
                );
                return null;
            }
        }

        // String path (EXT: notation or public-relative path)
        if (is_string($image) && $image !== '') {
            $absolutePath = GeneralUtility::getFileAbsFileName($image);
            if ($absolutePath === '' || !is_file($absolutePath)) {
                $this->logger->warning('maispace_assets: Image file not found: ' . $image);
                return null;
            }

            try {
                return $resourceFactory->retrieveFileOrFolderObject($absolutePath);
            } catch (\Exception $e) {
                $this->logger->warning(
                    'maispace_assets: Could not retrieve FAL object for "' . $image . '": ' . $e->getMessage(),
                );
                return null;
            }
        }

        $this->logger->warning('maispace_assets: Unsupported image input type: ' . gettype($image));
        return null;
    }

    // -------------------------------------------------------------------------
    // Image processing
    // -------------------------------------------------------------------------

    /**
     * Process the image file to the requested dimensions.
     *
     * Delegates to TYPO3's ImageService which respects the configured image processor
     * (GraphicsMagick / ImageMagick / GD) and any WebP conversion settings.
     *
     * Results are cached statically within the current request to avoid duplicate
     * processing when the same image/dimensions combination appears multiple times.
     *
     * Dispatches BeforeImageProcessingEvent before and AfterImageProcessedEvent after
     * processing, allowing listeners to modify instructions, force formats, or replace
     * the resulting ProcessedFile.
     *
     * @param string $fileExtension Optional target format override, e.g. "webp" or "avif".
     *                              When empty, the format from BeforeImageProcessingEvent
     *                              or the source file format is used.
     * @param int    $quality       JPEG/WebP/AVIF quality (1–100). 0 means "use ImageService default".
     */
    public function processImage(
        File|FileReference $file,
        string $width,
        string $height,
        string $fileExtension = '',
        int $quality = 0,
    ): ProcessedFile {
        $cacheKey = $this->buildProcessingCacheKey($file, $width, $height, $fileExtension, $quality);

        if (isset(self::$processedFileCache[$cacheKey])) {
            return self::$processedFileCache[$cacheKey];
        }

        $instructions = [];
        if ($width !== '') {
            $instructions['width'] = $width;
        }
        if ($height !== '') {
            $instructions['height'] = $height;
        }
        if ($fileExtension !== '') {
            $instructions['fileExtension'] = $fileExtension;
        }
        if ($quality > 0) {
            $instructions['quality'] = $quality;
        }

        // Dispatch BeforeImageProcessingEvent — listeners can modify instructions or skip.
        $beforeEvent = new BeforeImageProcessingEvent($file, $instructions);
        $this->eventDispatcher->dispatch($beforeEvent);

        if ($beforeEvent->isSkipped()) {
            // Return the original file as a pass-through ProcessedFile.
            $processed = $this->imageService->applyProcessingInstructions($file, []);
        } else {
            $processed = $this->imageService->applyProcessingInstructions($file, $beforeEvent->getInstructions());
        }

        // Dispatch AfterImageProcessedEvent — listeners can replace or inspect the result.
        $afterEvent = new AfterImageProcessedEvent($file, $processed, $beforeEvent->getInstructions());
        $this->eventDispatcher->dispatch($afterEvent);

        $processed = $afterEvent->getProcessedFile();

        self::$processedFileCache[$cacheKey] = $processed;

        return $processed;
    }

    /**
     * Process the same image to multiple target formats and return one ProcessedFile
     * per format.
     *
     * This is the engine behind automatic format source sets in `<mai:picture>` and
     * `<mai:picture.source>`. Given formats ["avif", "webp"], it will return two
     * processed files in that order — the caller is responsible for rendering
     * `<source>` tags (most capable format first) followed by the `<img>` fallback.
     *
     * Only formats listed in $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] and
     * supported by the configured image processor are guaranteed to succeed. If a
     * format is unsupported, the ImageService will silently fall back to the source
     * format — the returned ProcessedFile will have the original extension.
     *
     * @param File|FileReference   $file       The source image to process
     * @param string               $width      Width in TYPO3 notation (e.g. "800", "800c")
     * @param string               $height     Height in TYPO3 notation
     * @param list<string>         $formats    Target formats in preference order, e.g. ["avif", "webp"]
     * @return array<string, ProcessedFile>    Keyed by format string, in the order given
     */
    public function processImageAlternatives(
        File|FileReference $file,
        string $width,
        string $height,
        array $formats,
        int $quality = 0,
    ): array {
        $results = [];

        foreach ($formats as $format) {
            $format = strtolower(trim($format));
            if ($format === '') {
                continue;
            }
            $results[$format] = $this->processImage($file, $width, $height, $format, $quality);
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // HTML rendering
    // -------------------------------------------------------------------------

    /**
     * Build a `srcset` attribute string from a comma-separated list of target widths.
     *
     * Each width value is processed independently via `processImage()`. The resulting URL
     * and the actual rendered pixel width (from the ProcessedFile's `width` property)
     * form each `url Nw` entry — so the descriptor always reflects the true output size.
     *
     * Width entries follow TYPO3 notation (e.g. `"400"`, `"800c"`, `"1200m"`).
     *
     * @param string $widths        Comma-separated width values, e.g. `"400, 800, 1200"`
     * @param string $height        Height constraint shared across all widths (empty = proportional).
     * @param string $fileExtension Target format override, e.g. `"webp"` or `""` for source format.
     * @return string               Srcset string, e.g. `"/img_400.jpg 400w, /img_800.jpg 800w"`
     */
    public function buildSrcsetString(
        File|FileReference $file,
        string $widths,
        string $height = '',
        string $fileExtension = '',
        int $quality = 0,
    ): string {
        $widthList = array_filter(array_map('trim', explode(',', $widths)));
        $parts = [];

        foreach ($widthList as $w) {
            if ($w === '') {
                continue;
            }
            $processed   = $this->processImage($file, $w, $height, $fileExtension, $quality);
            $url         = $this->imageService->getImageUri($processed, true);
            $actualWidth = (int)($processed->getProperty('width') ?: (int)preg_replace('/\D+/', '', $w));
            if ($url !== '' && $actualWidth > 0) {
                $parts[] = $url . ' ' . $actualWidth . 'w';
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Build an `<img>` tag string.
     *
     * @param array{
     *   alt: string,
     *   class?: string|null,
     *   id?: string|null,
     *   title?: string|null,
     *   lazyloading?: bool|null,
     *   lazyloadWithClass?: string|null,
     *   fetchPriority?: string|null,
     *   srcset?: string|null,
     *   sizes?: string|null,
     *   additionalAttributes?: array<string,string>,
     * } $options
     */
    public function renderImgTag(ProcessedFile $processed, array $options): string
    {
        $url    = $this->imageService->getImageUri($processed, true);
        $width  = $processed->getProperty('width');
        $height = $processed->getProperty('height');

        $attrs = [];
        $attrs['src']    = $url;
        $attrs['width']  = (string)($width ?: '');
        $attrs['height'] = (string)($height ?: '');
        $attrs['alt']    = $options['alt'] ?? '';

        // Lazy loading
        $isLazy = (bool)($options['lazyloading'] ?? false);
        $lazyClass = $options['lazyloadWithClass'] ?? null;
        if ($lazyClass !== null && $lazyClass !== '') {
            $isLazy = true;
        }
        if ($isLazy) {
            $attrs['loading'] = 'lazy';
        }

        // CSS class (merge with lazyClass if set)
        $classes = [];
        if (!empty($options['class'])) {
            $classes[] = $options['class'];
        }
        if ($lazyClass !== null && $lazyClass !== '') {
            $classes[] = $lazyClass;
        }
        if ($classes !== []) {
            $attrs['class'] = implode(' ', $classes);
        }

        if (!empty($options['id'])) {
            $attrs['id'] = $options['id'];
        }

        if (!empty($options['title'])) {
            $attrs['title'] = $options['title'];
        }

        $fetchPriority = $options['fetchPriority'] ?? null;
        if ($fetchPriority !== null && in_array($fetchPriority, ['high', 'low', 'auto'], true)) {
            $attrs['fetchpriority'] = $fetchPriority;
        }

        // srcset / sizes — for responsive images without <picture>
        $srcset = $options['srcset'] ?? null;
        if ($srcset !== null && $srcset !== '') {
            $attrs['srcset'] = $srcset;
        }
        $sizes = $options['sizes'] ?? null;
        if ($sizes !== null && $sizes !== '') {
            $attrs['sizes'] = $sizes;
        }

        // Additional attributes (caller-supplied, not escaped — trust the caller)
        foreach (($options['additionalAttributes'] ?? []) as $name => $value) {
            $attrs[$name] = $value;
        }

        return $this->buildTag('img', $attrs, selfClosing: true);
    }

    /**
     * Build a `<source>` tag string for use inside a `<picture>` element.
     *
     * @param string|null $media  Media query, e.g. `(min-width: 768px)`
     * @param string|null $type   MIME type override; auto-detected from processed file when null
     */
    public function renderSourceTag(ProcessedFile $processed, ?string $media, ?string $type = null): string
    {
        $url = $this->imageService->getImageUri($processed, true);

        $attrs = [];
        $attrs['srcset'] = $url;

        if ($media !== null && $media !== '') {
            $attrs['media'] = $media;
        }

        $mimeType = $type ?? $this->detectMimeType($processed);
        if ($mimeType !== '') {
            $attrs['type'] = $mimeType;
        }

        return $this->buildTag('source', $attrs, selfClosing: true);
    }

    // -------------------------------------------------------------------------
    // Preload
    // -------------------------------------------------------------------------

    /**
     * Add `<link rel="preload" as="image">` to the page `<head>`.
     *
     * @param string|null $media Optional media query to scope the preload hint
     */
    public function addImagePreloadHeader(string $url, ?string $media = null): void
    {
        $attrs = [
            'rel'  => 'preload',
            'href' => $url,
            'as'   => 'image',
        ];

        if ($media !== null && $media !== '') {
            $attrs['media'] = $media;
        }

        $tag = $this->buildTag('link', $attrs, selfClosing: true);

        GeneralUtility::makeInstance(PageRenderer::class)->addHeaderData($tag);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build a stable cache key for processed file lookup within the current request.
     */
    private function buildProcessingCacheKey(
        File|FileReference $file,
        string $width,
        string $height,
        string $fileExtension = '',
        int $quality = 0,
    ): string {
        $fileUid = $file instanceof FileReference ? $file->getOriginalFile()->getUid() : $file->getUid();
        return $fileUid . '_' . md5($width . 'x' . $height . ':' . $fileExtension . ':q' . $quality);
    }

    /**
     * Detect the MIME type of a processed file from its file extension.
     */
    private function detectMimeType(ProcessedFile $processed): string
    {
        $ext = strtolower(pathinfo($processed->getIdentifier(), PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            'svg'         => 'image/svg+xml',
            'avif'        => 'image/avif',
            default       => '',
        };
    }

    /**
     * Render an HTML tag with the given attributes.
     * Attribute values are HTML-escaped. Attribute names are trusted (internal use only).
     */
    private function buildTag(string $tagName, array $attrs, bool $selfClosing = false): string
    {
        $attrString = '';
        foreach ($attrs as $name => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $attrString .= ' ' . $name . '="' . htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1) . '"';
        }

        if ($selfClosing) {
            return '<' . $tagName . $attrString . '>';
        }

        return '<' . $tagName . $attrString . '></' . $tagName . '>';
    }
}
