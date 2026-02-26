<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\ViewHelpers\Picture;

use Closure;
use Maispace\MaispaceAssets\Service\ImageRenderingService;
use Maispace\MaispaceAssets\ViewHelpers\PictureViewHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;

/**
 * Render one or more `<source>` tags inside a `<mai:picture>` element.
 *
 * Must be used as a direct child of `<mai:picture>`. The parent ViewHelper shares
 * the resolved image via `ViewHelperVariableContainer`; the source ViewHelper
 * inherits it unless an explicit `image` override is provided.
 *
 * The image is processed to the specified dimensions independently from the
 * parent's fallback `<img>` — each breakpoint gets its own optimised file.
 *
 * Format alternatives (automatic source sets)
 * ============================================
 * The `formats` argument accepts a comma-separated list of target formats in
 * preference order (most capable first), e.g. `"avif, webp"`. For each format
 * one `<source>` tag is rendered before the default `<source>` tag. Browsers
 * pick the first format they support.
 *
 * Example output for `formats="avif, webp"` with `media="(min-width: 768px)"`:
 *
 *   <source srcset="…1200.avif" media="(min-width: 768px)" type="image/avif">
 *   <source srcset="…1200.webp" media="(min-width: 768px)" type="image/webp">
 *   <source srcset="…1200.jpg"  media="(min-width: 768px)" type="image/jpeg">
 *
 * The trailing source for the original format acts as a browser fallback and is
 * always emitted unless `fallback="false"` is passed.
 *
 * TypoScript global defaults
 * ==========================
 * When `formats` is not set on the ViewHelper, the extension reads the TypoScript
 * setting `plugin.tx_maispace_assets.image.alternativeFormats`. Set it to a
 * comma-separated list to enable automatic format source sets globally:
 *
 *   plugin.tx_maispace_assets.image.alternativeFormats = avif, webp
 *
 * Global namespace: declared as "mai" in ext_localconf.php.
 *
 * Usage examples:
 *
 *   <!-- Classic responsive breakpoints, no format conversion -->
 *   <mai:picture image="{imageRef}" alt="{alt}" width="1200">
 *       <mai:picture.source media="(min-width: 980px)" width="1200" height="675" />
 *       <mai:picture.source media="(min-width: 768px)" width="800" height="450" />
 *       <mai:picture.source media="(max-width: 767px)" width="400" height="225" />
 *   </mai:picture>
 *
 *   <!-- Auto format alternatives: avif + webp sources before the JPEG fallback -->
 *   <mai:picture image="{imageRef}" alt="{alt}" width="1200">
 *       <mai:picture.source media="(min-width: 768px)" width="1200" formats="avif, webp" />
 *       <mai:picture.source media="(max-width: 767px)" width="400" formats="avif, webp" />
 *   </mai:picture>
 *
 *   <!-- Only modern formats, no original-format fallback source -->
 *   <mai:picture.source media="(min-width: 768px)" width="1200"
 *                       formats="avif, webp" fallback="false" />
 *
 *   <!-- Explicit MIME type (e.g. to force WebP on a single source) -->
 *   <mai:picture.source media="(min-width: 768px)" width="1200" type="image/webp" />
 *
 *   <!-- Different (portrait) image for small viewports -->
 *   <mai:picture.source image="{portraitRef}" media="(max-width: 767px)" width="400" height="600" />
 *
 * @see \Maispace\MaispaceAssets\ViewHelpers\PictureViewHelper
 * @see \Maispace\MaispaceAssets\Service\ImageRenderingService
 */
final class SourceViewHelper extends AbstractViewHelper
{
    use CompileWithRenderStatic;

    /** Disable output escaping — this ViewHelper returns raw HTML. */
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument(
            'media',
            'string',
            'Media query that activates this source, e.g. "(min-width: 768px)". Omit to create a catch-all source.',
            false,
            null,
        );

        $this->registerArgument(
            'width',
            'string',
            'Target image width in TYPO3 notation: "800" (exact), "800c" (crop), "800m" (max). Defaults to the parent picture width when empty.',
            false,
            '',
        );

        $this->registerArgument(
            'height',
            'string',
            'Target image height in TYPO3 notation. Derived proportionally from width when empty.',
            false,
            '',
        );

        $this->registerArgument(
            'image',
            'mixed',
            'Override the image for this breakpoint. Inherits the parent <mai:picture> image when not set.',
            false,
            null,
        );

        $this->registerArgument(
            'type',
            'string',
            'MIME type for the <source> tag (e.g. "image/webp"). Auto-detected from the processed file extension when omitted. Has no effect when formats is set.',
            false,
            null,
        );

        $this->registerArgument(
            'formats',
            'string',
            'Comma-separated list of target formats in preference order, e.g. "avif, webp". '
            . 'Renders one <source> per format before the original-format source. '
            . 'Falls back to the TypoScript setting plugin.tx_maispace_assets.image.alternativeFormats when not set.',
            false,
            null,
        );

        $this->registerArgument(
            'fallback',
            'bool',
            'When formats is set, also emit a <source> for the original (unmodified) format as a browser fallback. Defaults to true.',
            false,
            true,
        );

        $this->registerArgument(
            'fileExtension',
            'string',
            'Force the output format for this source when formats is not set, e.g. "webp" or "avif". '
            . 'Overrides the TypoScript setting plugin.tx_maispace_assets.image.forceFormat. '
            . 'Has no effect when the formats argument is set.',
            false,
            null,
        );
    }

    public static function renderStatic(
        array $arguments,
        Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext,
    ): string {
        /** @var ImageRenderingService $service */
        $service      = GeneralUtility::makeInstance(ImageRenderingService::class);
        $varContainer = $renderingContext->getViewHelperVariableContainer();

        // Resolve the image: explicit override or inherited from parent PictureViewHelper.
        $imageArg = $arguments['image'] ?? null;
        if ($imageArg !== null) {
            $file = $service->resolveImage($imageArg);
        } else {
            /** @var \TYPO3\CMS\Core\Resource\File|\TYPO3\CMS\Core\Resource\FileReference|null $file */
            $file = $varContainer->get(PictureViewHelper::class, PictureViewHelper::VAR_FILE);
        }

        if ($file === null) {
            return '';
        }

        $width  = (string)$arguments['width'];
        $height = (string)$arguments['height'];
        $media  = $arguments['media'] ?? null;

        // Resolve alternative formats: argument → TypoScript default.
        $formats = self::resolveAlternativeFormats($arguments['formats'] ?? null);

        // No format alternatives: render a single <source> tag (classic behaviour).
        if ($formats === []) {
            $fileExtension = self::resolveFileExtension($arguments);
            $processed = $service->processImage($file, $width, $height, $fileExtension);
            return $service->renderSourceTag($processed, $media, $arguments['type'] ?? null);
        }

        // Format alternatives: render one <source> per alternative format, then the fallback.
        $html = '';

        $alternatives = $service->processImageAlternatives($file, $width, $height, $formats);
        foreach ($alternatives as $processed) {
            $html .= $service->renderSourceTag($processed, $media);
        }

        // Fallback <source> in the original/default format (no fileExtension override).
        if ((bool)($arguments['fallback'] ?? true)) {
            $fallbackProcessed = $service->processImage($file, $width, $height);
            $html .= $service->renderSourceTag($fallbackProcessed, $media, $arguments['type'] ?? null);
        }

        return $html;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the effective output file extension when formats is not set.
     *
     * Priority: explicit `fileExtension` argument → TypoScript `image.forceFormat` → ""
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
     * Resolve the list of alternative formats from the ViewHelper argument or TypoScript.
     *
     * Returns an empty array when no formats are configured, signalling that the classic
     * single-source behaviour should be used.
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
