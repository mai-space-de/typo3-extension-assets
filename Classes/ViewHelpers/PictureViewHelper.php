<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\ViewHelpers;

use Closure;
use Maispace\MaispaceAssets\Service\ImageRenderingService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;

/**
 * Render a responsive `<picture>` element.
 *
 * Child `<mai:picture.source>` ViewHelpers define the `<source>` tags. A fallback
 * `<img>` is appended automatically using the parent image and dimensions.
 *
 * The resolved image and lazy-loading settings are shared with child ViewHelpers
 * via the `ViewHelperVariableContainer` so that each `<mai:picture.source>` can
 * inherit the parent image without repeating it.
 *
 * Global namespace: declared as "mai" in ext_localconf.php.
 *
 * Usage examples:
 *
 *   <!-- Basic responsive picture -->
 *   <mai:picture image="{imageRef}" alt="{alt}" width="1200" height="675">
 *       <mai:picture.source media="(min-width: 980px)" width="1200" height="675" />
 *       <mai:picture.source media="(min-width: 768px)" width="800" height="450" />
 *       <mai:picture.source media="(max-width: 767px)" width="400" height="225" />
 *   </mai:picture>
 *
 *   <!-- Different image per breakpoint -->
 *   <mai:picture image="{desktopImg}" alt="{alt}" width="1200">
 *       <mai:picture.source media="(min-width: 768px)" width="1200" />
 *       <mai:picture.source image="{mobileImg}" media="(max-width: 767px)" width="400" />
 *   </mai:picture>
 *
 *   <!-- Hero: preloaded, high fetchpriority fallback, no lazy -->
 *   <mai:picture image="{hero}" alt="{alt}" width="1920" lazyloading="false"
 *               fetchPriority="high" preload="true" />
 *
 *   <!-- With lazy-load class for JS hook -->
 *   <mai:picture image="{img}" alt="{alt}" width="800" lazyloadWithClass="lazyload">
 *       <mai:picture.source media="(min-width: 768px)" width="1200" />
 *   </mai:picture>
 *
 * Format alternatives (automatic source sets)
 * ============================================
 * The `formats` argument renders additional `<source>` tags before the fallback `<img>`,
 * one per format in preference order (most capable first). This allows browsers to pick
 * the best supported format without template duplication.
 *
 * Example with formats="avif, webp":
 *
 *   <mai:picture image="{img}" alt="{alt}" width="1200" formats="avif, webp">
 *       <mai:picture.source media="(min-width: 768px)" width="1200" formats="avif, webp" />
 *   </mai:picture>
 *
 * Output:
 *   <picture>
 *     <source srcset="…1200.avif" media="(min-width: 768px)" type="image/avif">
 *     <source srcset="…1200.webp" media="(min-width: 768px)" type="image/webp">
 *     <source srcset="…1200.jpg"  media="(min-width: 768px)" type="image/jpeg">
 *     <source srcset="…1200.avif" type="image/avif">
 *     <source srcset="…1200.webp" type="image/webp">
 *     <img src="…1200.jpg" …>
 *   </picture>
 *
 * TypoScript global default for all images:
 *   plugin.tx_maispace_assets.image.alternativeFormats = avif, webp
 *
 * @see \Maispace\MaispaceAssets\ViewHelpers\Picture\SourceViewHelper
 * @see \Maispace\MaispaceAssets\Service\ImageRenderingService
 */
final class PictureViewHelper extends AbstractViewHelper
{
    use CompileWithRenderStatic;

    /** Variable container keys used to share state with child SourceViewHelper. */
    public const VAR_FILE            = 'ma_picture_file';
    public const VAR_LAZYLOADING     = 'ma_picture_lazyloading';
    public const VAR_LAZYLOAD_CLASS  = 'ma_picture_lazyload_class';

    /** Disable output escaping — this ViewHelper returns raw HTML. */
    protected $escapeOutput = false;

    /** Allow child ViewHelpers (SourceViewHelper) to render unescaped. */
    protected $escapeChildren = false;

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
            'Alt text for the fallback <img> tag. Pass an empty string for decorative images.',
            true,
        );

        $this->registerArgument(
            'width',
            'string',
            'Width for the fallback <img> in TYPO3 notation: "800" (exact), "800c" (crop), "800m" (max).',
            false,
            '',
        );

        $this->registerArgument(
            'height',
            'string',
            'Height for the fallback <img> in TYPO3 notation.',
            false,
            '',
        );

        $this->registerArgument(
            'lazyloading',
            'bool',
            'Add loading="lazy" to the fallback <img>. Also propagated to child <mai:picture.source> elements. Null uses the TypoScript default.',
            false,
            null,
        );

        $this->registerArgument(
            'lazyloadWithClass',
            'string',
            'CSS class added alongside loading="lazy" on the fallback <img>. Also propagated to children. Null uses the TypoScript default.',
            false,
            null,
        );

        $this->registerArgument(
            'fetchPriority',
            'string',
            'fetchpriority attribute on the fallback <img>. Allowed: "high", "low", "auto".',
            false,
            null,
        );

        $this->registerArgument(
            'preload',
            'bool',
            'Add a <link rel="preload" as="image"> for the fallback image URL.',
            false,
            false,
        );

        $this->registerArgument(
            'class',
            'string',
            'CSS class(es) for the <picture> element.',
            false,
            null,
        );

        $this->registerArgument(
            'additionalAttributes',
            'array',
            'Additional HTML attributes merged onto the <picture> tag.',
            false,
            [],
        );

        $this->registerArgument(
            'formats',
            'string',
            'Comma-separated list of target formats in preference order, e.g. "avif, webp". '
            . 'Renders additional <source> tags before the fallback <img> so browsers can pick the best supported format. '
            . 'Falls back to the TypoScript setting plugin.tx_maispace_assets.image.alternativeFormats when not set.',
            false,
            null,
        );

        $this->registerArgument(
            'fallback',
            'bool',
            'When formats is set, also emit a <source> for the original (unmodified) format directly before the fallback <img>. Defaults to true.',
            false,
            true,
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

        // Resolve lazy loading settings (argument → TypoScript fallback)
        [$lazyloading, $lazyloadWithClass] = self::resolveLazyArguments($arguments);

        // Share resolved file + lazy settings with child SourceViewHelpers.
        $varContainer = $renderingContext->getViewHelperVariableContainer();
        $varContainer->add(self::class, self::VAR_FILE, $file);
        $varContainer->add(self::class, self::VAR_LAZYLOADING, $lazyloading);
        $varContainer->add(self::class, self::VAR_LAZYLOAD_CLASS, $lazyloadWithClass);

        // Render children — each <mai:picture.source> outputs one or more <source> tags.
        $sourcesHtml = (string)$renderChildrenClosure();

        $varContainer->remove(self::class, self::VAR_FILE);
        $varContainer->remove(self::class, self::VAR_LAZYLOADING);
        $varContainer->remove(self::class, self::VAR_LAZYLOAD_CLASS);

        $width  = (string)$arguments['width'];
        $height = (string)$arguments['height'];

        // Resolve alternative formats for the fallback area (catch-all sources + img).
        $formats = self::resolveAlternativeFormats($arguments['formats'] ?? null);

        // Render format-alternative catch-all <source> tags before the fallback <img>.
        $fallbackSourcesHtml = '';
        if ($formats !== []) {
            $alternatives = $service->processImageAlternatives($file, $width, $height, $formats);
            foreach ($alternatives as $altProcessed) {
                $fallbackSourcesHtml .= $service->renderSourceTag($altProcessed, null);
            }

            // Original-format catch-all <source> (browser fallback within <picture>).
            if ((bool)($arguments['fallback'] ?? true)) {
                $originalProcessed    = $service->processImage($file, $width, $height);
                $fallbackSourcesHtml .= $service->renderSourceTag($originalProcessed, null);
            }
        }

        // Build fallback <img>.
        $processed = $service->processImage($file, $width, $height);
        $imgHtml   = $service->renderImgTag($processed, [
            'alt'               => (string)($arguments['alt'] ?? ''),
            'lazyloading'       => $lazyloading,
            'lazyloadWithClass' => $lazyloadWithClass,
            'fetchPriority'     => $arguments['fetchPriority'] ?? null,
            'additionalAttributes' => (array)($arguments['additionalAttributes'] ?? []),
        ]);

        // Optionally preload the fallback image.
        if ((bool)($arguments['preload'] ?? false)) {
            $imageService = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Service\ImageService::class);
            $url = $imageService->getImageUri($processed, true);
            $service->addImagePreloadHeader($url);
        }

        // Build <picture> element.
        $pictureAttrs = self::buildPictureAttributes($arguments);

        return '<picture' . $pictureAttrs . '>'
            . $sourcesHtml
            . $fallbackSourcesHtml
            . $imgHtml
            . '</picture>';
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{0: bool, 1: string|null}  [isLazy, lazyClass|null]
     */
    private static function resolveLazyArguments(array $arguments): array
    {
        $lazyloading      = $arguments['lazyloading'] ?? null;
        $lazyloadWithClass = $arguments['lazyloadWithClass'] ?? null;

        if ($lazyloading === null && $lazyloadWithClass === null) {
            $tsLazy      = self::getTypoScriptSetting('image.lazyloading', null);
            $tsLazyClass = self::getTypoScriptSetting('image.lazyloadWithClass', null);

            $lazyloading       = $tsLazy !== null ? (bool)$tsLazy : false;
            $lazyloadWithClass = is_string($tsLazyClass) && $tsLazyClass !== '' ? $tsLazyClass : null;
        }

        return [(bool)$lazyloading, $lazyloadWithClass !== '' ? $lazyloadWithClass : null];
    }

    /**
     * Resolve the list of alternative formats from the ViewHelper argument or TypoScript.
     *
     * Returns an empty array when no formats are configured.
     *
     * @return list<string>
     */
    private static function resolveAlternativeFormats(?string $formatsArg): array
    {
        $raw = $formatsArg;

        if ($raw === null) {
            $raw = self::getTypoScriptSetting('image.alternativeFormats', null);
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $formats = array_filter(array_map('trim', explode(',', $raw)));
        return array_values(array_map('strtolower', $formats));
    }

    private static function buildPictureAttributes(array $arguments): string
    {
        $attrs = '';
        if (!empty($arguments['class'])) {
            $attrs .= ' class="' . htmlspecialchars((string)$arguments['class'], ENT_QUOTES | ENT_XML1) . '"';
        }
        foreach ((array)($arguments['additionalAttributes'] ?? []) as $name => $value) {
            $attrs .= ' ' . htmlspecialchars($name, ENT_QUOTES | ENT_XML1)
                . '="' . htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1) . '"';
        }
        return $attrs;
    }

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
