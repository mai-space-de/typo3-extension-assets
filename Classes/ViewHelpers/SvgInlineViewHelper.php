<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\ViewHelpers;

use Closure;
use Maispace\MaispaceAssets\Cache\AssetCacheManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;

/**
 * Embed an SVG file directly as inline markup.
 *
 * Unlike `<mai:svgSprite>` (which outputs an `<svg><use>` sprite reference),
 * this ViewHelper reads the source file and injects the full `<svg>` element
 * into the HTML document. This is required when:
 *  - The SVG needs to be styled via CSS (e.g. `fill: currentColor`)
 *  - The SVG contains animations driven by JavaScript
 *  - The SVG is a logo that must render without a separate network request
 *
 * Security note: the `src` argument must point to a trusted file on the filesystem
 * (EXT: notation or a site-relative path). User-supplied SVG content is NOT safe
 * to embed inline without sanitization. This ViewHelper trusts its input.
 *
 * The processed SVG is cached in the maispace_assets TYPO3 cache to avoid
 * repeated file reads and DOM manipulation on every page request.
 *
 * Global namespace: declared as "mai" in ext_localconf.php.
 *
 * Usage examples:
 *
 *   <!-- Decorative logo (aria-hidden="true" added automatically) -->
 *   <mai:svgInline src="EXT:theme/Resources/Public/Icons/logo.svg"
 *                 class="logo" width="120" height="40" />
 *
 *   <!-- Meaningful SVG with accessible label -->
 *   <mai:svgInline src="EXT:theme/Resources/Public/Icons/checkmark.svg"
 *                 aria-label="Success" width="24" height="24" />
 *
 *   <!-- SVG with custom title element for screen readers -->
 *   <mai:svgInline src="EXT:theme/Resources/Public/Icons/logo.svg"
 *                 title="Company Logo" aria-label="Company Logo" />
 *
 *   <!-- Keep original SVG attributes, just add a class -->
 *   <mai:svgInline src="EXT:theme/Resources/Public/Animations/wave.svg"
 *                 class="wave-animation" />
 */
final class SvgInlineViewHelper extends AbstractViewHelper
{
    use CompileWithRenderStatic;

    /** Disable output escaping — this ViewHelper returns raw SVG markup. */
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument(
            'src',
            'string',
            'EXT: path (e.g. EXT:my_ext/Resources/Public/Icons/logo.svg) or absolute file system path to the SVG file.',
            true,
        );

        $this->registerArgument(
            'class',
            'string',
            'CSS class(es) to set on the root <svg> element. Replaces any existing class attribute in the source file.',
            false,
            null,
        );

        $this->registerArgument(
            'width',
            'string',
            'Override the width attribute on the root <svg> element (e.g. "24" or "1.5rem").',
            false,
            null,
        );

        $this->registerArgument(
            'height',
            'string',
            'Override the height attribute on the root <svg> element.',
            false,
            null,
        );

        $this->registerArgument(
            'aria-hidden',
            'string',
            'aria-hidden attribute. Defaults to "true" for decorative SVGs. Set to "false" explicitly '
            . 'when combined with aria-label to expose the SVG to screen readers.',
            false,
            null,
        );

        $this->registerArgument(
            'aria-label',
            'string',
            'Accessible label for the SVG. When set, role="img" is added and aria-hidden is omitted.',
            false,
            null,
        );

        $this->registerArgument(
            'title',
            'string',
            'Set or replace the <title> element inside the SVG. The title is read by screen readers '
            . 'as an accessible name when no aria-label is present on the <svg> element.',
            false,
            null,
        );

        $this->registerArgument(
            'additionalAttributes',
            'array',
            'Additional HTML attributes merged onto the root <svg> element.',
            false,
            [],
        );
    }

    public static function renderStatic(
        array $arguments,
        Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext,
    ): string {
        $src = (string)($arguments['src'] ?? '');
        if ($src === '') {
            return '';
        }

        // Build a stable cache key from all arguments that affect the rendered output.
        $cacheKey = 'svginline_' . md5(implode('|', [
            $src,
            $arguments['class'] ?? '',
            $arguments['width'] ?? '',
            $arguments['height'] ?? '',
            $arguments['aria-hidden'] ?? '',
            $arguments['aria-label'] ?? '',
            $arguments['title'] ?? '',
            serialize($arguments['additionalAttributes'] ?? []),
        ]));

        /** @var AssetCacheManager $cache */
        $cache = GeneralUtility::makeInstance(AssetCacheManager::class);

        if ($cache->has($cacheKey)) {
            return (string)$cache->get($cacheKey);
        }

        $result = self::buildSvgMarkup($arguments, $src);

        if ($result !== '') {
            $cache->set($cacheKey, $result, ['maispace_assets_svg']);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function buildSvgMarkup(array $arguments, string $src): string
    {
        $absolutePath = GeneralUtility::getFileAbsFileName($src);
        if ($absolutePath === '' || !is_file($absolutePath)) {
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)
                ->getLogger(__CLASS__)
                ->warning('maispace_assets: SVG file not found: ' . $src);
            return '';
        }

        $rawSvg = (string)file_get_contents($absolutePath);
        if ($rawSvg === '') {
            return '';
        }

        // Parse with DOMDocument. SVG files may have an XML declaration which is fine.
        $dom = new \DOMDocument();
        $dom->formatOutput = false;
        // Suppress warnings from malformed SVGs; errors are still caught below.
        $previous = libxml_use_internal_errors(true);
        $loaded   = $dom->loadXML($rawSvg);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)
                ->getLogger(__CLASS__)
                ->warning('maispace_assets: Could not parse SVG file: ' . $src);
            return '';
        }

        $svgElement = $dom->documentElement;
        if ($svgElement === null || strtolower($svgElement->tagName) !== 'svg') {
            return '';
        }

        // Apply attribute overrides.
        $class = $arguments['class'] ?? null;
        if (is_string($class) && $class !== '') {
            $svgElement->setAttribute('class', $class);
        }

        $width = $arguments['width'] ?? null;
        if (is_string($width) && $width !== '') {
            $svgElement->setAttribute('width', $width);
        }

        $height = $arguments['height'] ?? null;
        if (is_string($height) && $height !== '') {
            $svgElement->setAttribute('height', $height);
        }

        // Additional attributes.
        foreach ((array)($arguments['additionalAttributes'] ?? []) as $name => $value) {
            $svgElement->setAttribute((string)$name, (string)$value);
        }

        // Accessibility attributes.
        $ariaLabel = $arguments['aria-label'] ?? null;
        if (is_string($ariaLabel) && $ariaLabel !== '') {
            $svgElement->setAttribute('role', 'img');
            $svgElement->setAttribute('aria-label', $ariaLabel);
            $svgElement->removeAttribute('aria-hidden');
        } else {
            $ariaHidden = $arguments['aria-hidden'] ?? null;
            if ($ariaHidden === 'false') {
                $svgElement->setAttribute('aria-hidden', 'false');
            } else {
                $svgElement->setAttribute('aria-hidden', 'true');
            }
        }

        // Handle <title> injection/replacement.
        $titleText = $arguments['title'] ?? null;
        if (is_string($titleText) && $titleText !== '') {
            self::setTitleElement($dom, $svgElement, $titleText);
        }

        // Serialize only the <svg> element (no XML declaration, no DOCTYPE).
        $output = $dom->saveXML($svgElement);

        return is_string($output) ? $output : '';
    }

    /**
     * Set or replace the <title> element as the first child of the <svg>.
     *
     * The title must be the very first child for maximum screen reader compatibility.
     */
    private static function setTitleElement(\DOMDocument $dom, \DOMElement $svgElement, string $titleText): void
    {
        // Remove any existing <title> elements.
        $existing = $svgElement->getElementsByTagName('title');
        // Collect first, then remove (modifying live NodeList during iteration is unsafe).
        $toRemove = [];
        foreach ($existing as $node) {
            if ($node->parentNode === $svgElement) {
                $toRemove[] = $node;
            }
        }
        foreach ($toRemove as $node) {
            $svgElement->removeChild($node);
        }

        // Create new <title> and prepend it.
        $titleEl = $dom->createElement('title');
        $titleEl->appendChild($dom->createTextNode($titleText));
        $svgElement->insertBefore($titleEl, $svgElement->firstChild);
    }
}
