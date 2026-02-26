<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\Service;

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
 * Resolves, processes, and renders images for the ma:image, ma:picture, and
 * ma:picture.source ViewHelpers.
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
 */
final class ImageRenderingService implements SingletonInterface
{
    /** @var array<string, ProcessedFile> Static cache to avoid reprocessing the same image twice per request */
    private static array $processedFileCache = [];

    public function __construct(
        private readonly ImageService $imageService,
        private readonly LoggerInterface $logger,
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
     */
    public function processImage(File|FileReference $file, string $width, string $height): ProcessedFile
    {
        $cacheKey = $this->buildProcessingCacheKey($file, $width, $height);

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

        $processed = $this->imageService->applyProcessingInstructions($file, $instructions);

        self::$processedFileCache[$cacheKey] = $processed;

        return $processed;
    }

    // -------------------------------------------------------------------------
    // HTML rendering
    // -------------------------------------------------------------------------

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
    private function buildProcessingCacheKey(File|FileReference $file, string $width, string $height): string
    {
        $fileUid = $file instanceof FileReference ? $file->getOriginalFile()->getUid() : $file->getUid();
        return $fileUid . '_' . md5($width . 'x' . $height);
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
