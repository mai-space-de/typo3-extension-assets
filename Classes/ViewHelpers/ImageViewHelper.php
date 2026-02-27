<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\ViewHelpers;

use Closure;
use Maispace\MaispaceAssets\Service\ImageRenderingService;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Render a single responsive `<img>` tag with optional lazy loading, preloading,
 * and fetchpriority support.
 *
 * The image is processed via TYPO3's native ImageService, which handles resizing,
 * cropping, and format conversion (including WebP when configured in Install Tool).
 *
 * Global namespace: declared as "mai" in ext_localconf.php.
 *
 * Usage examples:
 *
 *   <!-- From a sys_file_reference UID (e.g. from a content element) -->
 *   <mai:image image="{file.uid}" alt="{file.alternative}" width="800" />
 *
 *   <!-- From an EXT: path (static/design image) -->
 *   <mai:image image="EXT:my_ext/Resources/Public/Images/logo.png" alt="Logo" width="200" />
 *
 *   <!-- Lazy loaded with a JS-hook class -->
 *   <mai:image image="{imageRef}" alt="{alt}" width="427c" height="240"
 *             lazyloadWithClass="lazyload" />
 *
 *   <!-- Above-the-fold hero: preloaded, high fetchpriority, no lazy -->
 *   <mai:image image="{hero}" alt="{heroAlt}" width="1920"
 *             preload="true" fetchPriority="high" />
 *
 *   <!-- Eager load (disable lazy even if TypoScript default is lazy) -->
 *   <mai:image image="{img}" alt="{alt}" width="400" lazyloading="false" />
 *
 * Width/height notation:
 *   800    → exact pixel width
 *   800c   → crop to exact width (centre crop)
 *   800m   → maximum width (proportional, never upscale)
 *
 * @see \Maispace\MaispaceAssets\Service\ImageRenderingService
 */
final class ImageViewHelper extends AbstractViewHelper
{
    use CompileWithRenderStatic;

    /** Disable output escaping — this ViewHelper returns raw HTML. */
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument(
            'image',
            'mixed',
            'Image source: sys_file_reference UID (int), FAL File/FileReference object, EXT: path, or public-relative path.',
            true,
        );

        $this->registerArgument(
            'alt',
            'string',
            'Alt text for the image. Pass an empty string for decorative images.',
            true,
        );

        $this->registerArgument(
            'width',
            'string',
            'Image width in TYPO3 notation: "800" (exact), "800c" (crop), "800m" (max). Leave empty to use original.',
            false,
            '',
        );

        $this->registerArgument(
            'height',
            'string',
            'Image height in TYPO3 notation. Leave empty to derive from width proportionally.',
            false,
            '',
        );

        $this->registerArgument(
            'lazyloading',
            'bool',
            'Add loading="lazy" to the <img> tag. Null uses the TypoScript default (plugin.tx_maispace_assets.image.lazyloading).',
            false,
            null,
        );

        $this->registerArgument(
            'lazyloadWithClass',
            'string',
            'CSS class name to add alongside loading="lazy". Enabling this also enables lazy loading. Useful for JS-based lazy loaders. Null uses the TypoScript default.',
            false,
            null,
        );

        $this->registerArgument(
            'fetchPriority',
            'string',
            'fetchpriority attribute on the <img> tag. Allowed: "high", "low", "auto". Use "high" for above-the-fold hero images.',
            false,
            null,
        );

        $this->registerArgument(
            'preload',
            'bool',
            'Add a <link rel="preload" as="image"> tag to <head>. Combine with fetchPriority="high" for critical images.',
            false,
            false,
        );

        $this->registerArgument(
            'class',
            'string',
            'CSS class(es) for the <img> element.',
            false,
            null,
        );

        $this->registerArgument(
            'id',
            'string',
            'id attribute for the <img> element.',
            false,
            null,
        );

        $this->registerArgument(
            'title',
            'string',
            'title attribute for the <img> element.',
            false,
            null,
        );

        $this->registerArgument(
            'additionalAttributes',
            'array',
            'Additional HTML attributes merged onto the <img> tag.',
            false,
            [],
        );

        $this->registerArgument(
            'srcset',
            'string',
            'Comma-separated list of widths to generate for the srcset attribute, e.g. "400, 800, 1200". '
            . 'Each width is processed independently; the actual output pixel width becomes the w-descriptor. '
            . 'Accepts the same TYPO3 width notation as the width argument (e.g. "400c", "800m"). '
            . 'The main src still uses the width/height arguments as usual.',
            false,
            null,
        );

        $this->registerArgument(
            'sizes',
            'string',
            'Value for the HTML sizes attribute, e.g. "(max-width: 768px) 100vw, 50vw". '
            . 'Only rendered when srcset is also set.',
            false,
            null,
        );

        $this->registerArgument(
            'fileExtension',
            'string',
            'Force the output image format, e.g. "webp" or "avif". '
            . 'Overrides the TypoScript setting plugin.tx_maispace_assets.image.forceFormat. '
            . 'Leave empty (null) to use the source file format or the global TypoScript default.',
            false,
            null,
        );

        $this->registerArgument(
            'quality',
            'int',
            'Image compression quality (1–100). Only meaningful for lossy formats (JPEG, WebP, AVIF). '
            . '0 (default) uses the ImageMagick/GraphicsMagick default configured in the Install Tool.',
            false,
            0,
        );
    }

    public static function renderStatic(
        array $arguments,
        Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext,
    ): string {
        /** @var ImageRenderingService $service */
        $service = GeneralUtility::makeInstance(ImageRenderingService::class);

        $file = $service->resolveImage($arguments['image']);
        if ($file === null) {
            return '';
        }

        // Resolve the output format: explicit argument → TypoScript forceFormat → source format.
        $fileExtension = self::resolveFileExtension($arguments);

        $quality = (int)($arguments['quality'] ?? 0);

        $processed = $service->processImage(
            $file,
            (string)$arguments['width'],
            (string)$arguments['height'],
            $fileExtension,
            $quality,
        );

        // Resolve lazy loading from arguments, then TypoScript fallback.
        [$lazyloading, $lazyloadWithClass] = self::resolveLazyArguments($arguments);

        // Build srcset string if srcset widths are specified.
        $srcsetString = null;
        $srcsetArg = $arguments['srcset'] ?? null;
        if (is_string($srcsetArg) && $srcsetArg !== '') {
            $srcsetString = $service->buildSrcsetString(
                $file,
                $srcsetArg,
                (string)$arguments['height'],
                $fileExtension,
                $quality,
            );
        }

        $imgHtml = $service->renderImgTag($processed, [
            'alt'               => (string)($arguments['alt'] ?? ''),
            'class'             => $arguments['class'] ?? null,
            'id'                => $arguments['id'] ?? null,
            'title'             => $arguments['title'] ?? null,
            'lazyloading'       => $lazyloading,
            'lazyloadWithClass' => $lazyloadWithClass,
            'fetchPriority'     => $arguments['fetchPriority'] ?? null,
            'srcset'            => $srcsetString,
            'sizes'             => $arguments['sizes'] ?? null,
            'additionalAttributes' => (array)($arguments['additionalAttributes'] ?? []),
        ]);

        if ((bool)($arguments['preload'] ?? false)) {
            $imageService = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Service\ImageService::class);
            $url = $imageService->getImageUri($processed, true);
            $service->addImagePreloadHeader($url);
        }

        return $imgHtml;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the effective output file extension (image format).
     *
     * Priority order:
     *  1. Explicit `fileExtension` ViewHelper argument
     *  2. TypoScript `plugin.tx_maispace_assets.image.forceFormat`
     *  3. Empty string → use source file format (no conversion)
     */
    private static function resolveFileExtension(array $arguments): string
    {
        $arg = $arguments['fileExtension'] ?? null;
        if (is_string($arg) && $arg !== '') {
            return $arg;
        }

        $ts = self::getTypoScriptSetting('image.forceFormat', '');
        return is_string($ts) ? $ts : '';
    }

    /**
     * Resolve the effective lazy-loading settings.
     *
     * Priority order:
     *  1. Explicit ViewHelper argument
     *  2. TypoScript default (plugin.tx_maispace_assets.image.lazyloading / .lazyloadWithClass)
     *
     * @return array{0: bool, 1: string|null}  [isLazy, lazyClass|null]
     */
    private static function resolveLazyArguments(array $arguments): array
    {
        $lazyloading      = $arguments['lazyloading'] ?? null;
        $lazyloadWithClass = $arguments['lazyloadWithClass'] ?? null;

        // If neither is explicitly set, check TypoScript defaults.
        if ($lazyloading === null && $lazyloadWithClass === null) {
            $tsLazy      = self::getTypoScriptSetting('image.lazyloading', null);
            $tsLazyClass = self::getTypoScriptSetting('image.lazyloadWithClass', null);

            $lazyloading       = $tsLazy !== null ? (bool)$tsLazy : false;
            $lazyloadWithClass = is_string($tsLazyClass) && $tsLazyClass !== '' ? $tsLazyClass : null;
        }

        return [(bool)$lazyloading, $lazyloadWithClass !== '' ? $lazyloadWithClass : null];
    }

    /**
     * Read a TypoScript setting from plugin.tx_maispace_assets.{dotPath}.
     */
    private static function getTypoScriptSetting(string $dotPath, mixed $default): mixed
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request === null) {
            return $default;
        }

        $fts = $request->getAttribute('frontend.typoscript');
        if ($fts === null) {
            return $default;
        }

        $setup = $fts->getSetupArray();
        $root  = $setup['plugin.']['tx_maispace_assets.'] ?? [];

        $parts = explode('.', $dotPath);
        $node  = $root;
        foreach ($parts as $i => $part) {
            $isLast = ($i === count($parts) - 1);
            if ($isLast) {
                return $node[$part] ?? $default;
            }
            $node = $node[$part . '.'] ?? [];
            if (!is_array($node)) {
                return $default;
            }
        }

        return $default;
    }
}
