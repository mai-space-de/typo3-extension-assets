<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\ViewHelpers;

use Closure;
use Maispace\MaispaceAssets\Service\ImageRenderingService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;

/**
 * Render a responsive `<picture>` element.
 *
 * Child `<ma:picture.source>` ViewHelpers define the `<source>` tags. A fallback
 * `<img>` is appended automatically using the parent image and dimensions.
 *
 * The resolved image and lazy-loading settings are shared with child ViewHelpers
 * via the `ViewHelperVariableContainer` so that each `<ma:picture.source>` can
 * inherit the parent image without repeating it.
 *
 * Global namespace: declared as "ma" in ext_localconf.php.
 *
 * Usage examples:
 *
 *   <!-- Basic responsive picture -->
 *   <ma:picture image="{imageRef}" alt="{alt}" width="1200" height="675">
 *       <ma:picture.source media="(min-width: 980px)" width="1200" height="675" />
 *       <ma:picture.source media="(min-width: 768px)" width="800" height="450" />
 *       <ma:picture.source media="(max-width: 767px)" width="400" height="225" />
 *   </ma:picture>
 *
 *   <!-- Different image per breakpoint -->
 *   <ma:picture image="{desktopImg}" alt="{alt}" width="1200">
 *       <ma:picture.source media="(min-width: 768px)" width="1200" />
 *       <ma:picture.source image="{mobileImg}" media="(max-width: 767px)" width="400" />
 *   </ma:picture>
 *
 *   <!-- Hero: preloaded, high fetchpriority fallback, no lazy -->
 *   <ma:picture image="{hero}" alt="{alt}" width="1920" lazyloading="false"
 *               fetchPriority="high" preload="true" />
 *
 *   <!-- With lazy-load class for JS hook -->
 *   <ma:picture image="{img}" alt="{alt}" width="800" lazyloadWithClass="lazyload">
 *       <ma:picture.source media="(min-width: 768px)" width="1200" />
 *   </ma:picture>
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
            'Add loading="lazy" to the fallback <img>. Also propagated to child <ma:picture.source> elements. Null uses the TypoScript default.',
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

        // Render children — each <ma:picture.source> outputs a <source> tag.
        $sourcesHtml = (string)$renderChildrenClosure();

        $varContainer->remove(self::class, self::VAR_FILE);
        $varContainer->remove(self::class, self::VAR_LAZYLOADING);
        $varContainer->remove(self::class, self::VAR_LAZYLOAD_CLASS);

        // Build fallback <img>.
        $processed = $service->processImage($file, (string)$arguments['width'], (string)$arguments['height']);
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
