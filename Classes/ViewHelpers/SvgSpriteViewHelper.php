<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\ViewHelpers;

use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;

/**
 * Output an `<svg><use>` reference to a symbol in the maispace_assets SVG sprite.
 *
 * The sprite is served by the SvgSpriteMiddleware from a dedicated, browser-cacheable
 * URL (default: `/maispace/sprite.svg`). Icons are registered in
 * `EXT:my_ext/Configuration/SpriteIcons.php` and auto-discovered across all loaded
 * TYPO3 extensions — no register or render calls are needed in templates.
 *
 * Global namespace: declared as "mai" in ext_localconf.php.
 *
 * Usage examples:
 *
 *   <!-- Decorative icon (aria-hidden="true" added automatically) -->
 *   <mai:svgSprite use="icon-arrow" width="24" height="24" class="icon" />
 *
 *   <!-- Meaningful icon with accessible label -->
 *   <mai:svgSprite use="icon-close" aria-label="Close dialog" width="20" height="20" />
 *
 *   <!-- Icon with title for additional screen reader context -->
 *   <mai:svgSprite use="icon-external" title="Opens in a new window" class="icon" />
 *
 *   <!-- Custom sprite URL (multi-sprite setups) -->
 *   <mai:svgSprite use="brand-logo" src="/custom/brand-sprite.svg" width="120" height="40" />
 *
 * Registering icons:
 *   Create `EXT:my_sitepackage/Configuration/SpriteIcons.php`:
 *
 *   <?php
 *   return [
 *       'icon-arrow' => ['src' => 'EXT:my_sitepackage/Resources/Public/Icons/arrow.svg'],
 *       'icon-close' => ['src' => 'EXT:my_sitepackage/Resources/Public/Icons/close.svg'],
 *   ];
 *
 * @see \Maispace\MaispaceAssets\Registry\SpriteIconRegistry
 * @see \Maispace\MaispaceAssets\Middleware\SvgSpriteMiddleware
 */
final class SvgSpriteViewHelper extends AbstractViewHelper
{
    use CompileWithRenderStatic;

    private const DEFAULT_ROUTE_PATH = '/maispace/sprite.svg';

    /** Disable output escaping — this ViewHelper returns raw SVG markup. */
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument(
            'use',
            'string',
            'Symbol ID to reference. Must match a key declared in a SpriteIcons.php file. Example: "icon-arrow".',
            true,
        );

        $this->registerArgument(
            'src',
            'string',
            'Override the sprite document URL. Useful when referencing a symbol from a different sprite. Defaults to the configured routePath (plugin.tx_maispace_assets.svgSprite.routePath).',
            false,
            null,
        );

        $this->registerArgument(
            'class',
            'string',
            'CSS class(es) for the outer <svg> element.',
            false,
            null,
        );

        $this->registerArgument(
            'width',
            'string',
            'width attribute for the <svg> element (e.g. "24" or "1.5rem").',
            false,
            null,
        );

        $this->registerArgument(
            'height',
            'string',
            'height attribute for the <svg> element.',
            false,
            null,
        );

        $this->registerArgument(
            'aria-hidden',
            'string',
            'aria-hidden attribute. Defaults to "true" for decorative icons. Set to "false" together with aria-label to expose the icon to screen readers.',
            false,
            null,
        );

        $this->registerArgument(
            'aria-label',
            'string',
            'Accessible label for the icon. When set, role="img" is added and aria-hidden is omitted. Use for meaningful icons that convey information not present in surrounding text.',
            false,
            null,
        );

        $this->registerArgument(
            'title',
            'string',
            'Optional <title> element inside the <svg> for additional screen reader context.',
            false,
            null,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function renderStatic(
        array $arguments,
        \Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext,
    ): string {
        $useRaw = $arguments['use'] ?? '';
        $symbolId = is_string($useRaw) ? $useRaw : '';
        if ($symbolId === '') {
            return '';
        }

        $srcArg = $arguments['src'] ?? null;
        $spriteUrl = self::resolveSpriteSrc(is_string($srcArg) ? $srcArg : null);
        $href = $spriteUrl . '#' . htmlspecialchars($symbolId, ENT_XML1);

        $attrs = self::buildSvgAttributes($arguments);

        $titleTag = '';
        $titleArg = is_string($arguments['title'] ?? null) ? $arguments['title'] : '';
        if ($titleArg !== '') {
            $titleTag = '<title>' . htmlspecialchars($titleArg) . '</title>';
        }

        return sprintf(
            '<svg%s>%s<use href="%s"></use></svg>',
            $attrs,
            $titleTag,
            $href,
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the sprite document URL.
     * Uses the explicit `src` argument, then the TypoScript setting, then the default.
     */
    private static function resolveSpriteSrc(?string $explicit): string
    {
        if ($explicit !== null && $explicit !== '') {
            return rtrim($explicit, '#');
        }

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof \Psr\Http\Message\ServerRequestInterface) {
            /** @var \TYPO3\CMS\Core\TypoScript\FrontendTypoScript|null $fts */
            $fts = $request->getAttribute('frontend.typoscript');
            if ($fts !== null) {
                /** @var array<string, mixed> $setup */
                $setup = $fts->getSetupArray();
                $plugin = is_array($setup['plugin.'] ?? null) ? $setup['plugin.'] : [];
                $txAssets = is_array($plugin['tx_maispace_assets.'] ?? null) ? $plugin['tx_maispace_assets.'] : [];
                $svgSprite = is_array($txAssets['svgSprite.'] ?? null) ? $txAssets['svgSprite.'] : [];
                $routePath = $svgSprite['routePath'] ?? '';
                if (is_string($routePath) && $routePath !== '') {
                    return '/' . ltrim(rtrim($routePath, '/'), '/');
                }
            }
        }

        return self::DEFAULT_ROUTE_PATH;
    }

    /**
     * Build the HTML attribute string for the outer `<svg>` element.
     *
     * Accessibility rules:
     * - aria-label set   → role="img" + aria-label, no aria-hidden
     * - aria-hidden=false → aria-hidden="false" explicitly
     * - default          → aria-hidden="true" (decorative icon)
     *
     * @param array<string, mixed> $arguments
     */
    private static function buildSvgAttributes(array $arguments): string
    {
        $attrs = [];

        $classArg = is_string($arguments['class'] ?? null) ? $arguments['class'] : '';
        if ($classArg !== '') {
            $attrs[] = 'class="' . htmlspecialchars($classArg) . '"';
        }
        $widthArg = is_string($arguments['width'] ?? null) ? $arguments['width'] : '';
        if ($widthArg !== '') {
            $attrs[] = 'width="' . htmlspecialchars($widthArg) . '"';
        }
        $heightArg = is_string($arguments['height'] ?? null) ? $arguments['height'] : '';
        if ($heightArg !== '') {
            $attrs[] = 'height="' . htmlspecialchars($heightArg) . '"';
        }

        $ariaLabel = is_string($arguments['aria-label'] ?? null) ? $arguments['aria-label'] : '';
        $ariaHidden = is_string($arguments['aria-hidden'] ?? null) ? $arguments['aria-hidden'] : '';

        if ($ariaLabel !== '') {
            $attrs[] = 'role="img"';
            $attrs[] = 'aria-label="' . htmlspecialchars($ariaLabel) . '"';
        } elseif ($ariaHidden === 'false') {
            $attrs[] = 'aria-hidden="false"';
        } else {
            $attrs[] = 'aria-hidden="true"';
        }

        return ' ' . implode(' ', $attrs);
    }
}
