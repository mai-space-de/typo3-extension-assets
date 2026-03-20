<?php

declare(strict_types = 1);

namespace Maispace\MaiAssets\ViewHelpers;

use Maispace\MaiAssets\ViewHelpers\Traits\TypoScriptSettingTrait;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

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
 * @see \Maispace\MaiAssets\Registry\SpriteIconRegistry
 * @see \Maispace\MaiAssets\Middleware\SvgSpriteMiddleware
 */
final class SvgSpriteViewHelper extends AbstractViewHelper
{
    use TypoScriptSettingTrait;

    private const DEFAULT_ROUTE_PATH = 'maispace/sprite.svg';

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

    public function render(): string
    {
        $useRaw = $this->arguments['use'] ?? '';
        $symbolId = is_string($useRaw) ? $useRaw : '';
        if ($symbolId === '') {
            return '';
        }

        $srcArg = $this->arguments['src'] ?? null;
        $spriteUrl = $this->resolveSpriteSrc(is_string($srcArg) ? $srcArg : null);
        $href = $spriteUrl . '#' . htmlspecialchars($symbolId, ENT_XML1);

        $attrs = $this->buildSvgAttributes($this->arguments);

        $titleTag = '';
        $titleArg = is_string($this->arguments['title'] ?? null) ? $this->arguments['title'] : '';
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
    private function resolveSpriteSrc(?string $explicit): string
    {
        if ($explicit !== null && $explicit !== '') {
            return rtrim($explicit, '#');
        }

        $routePathSetting = $this->getTypoScriptSetting('svgSprite.routePath', self::DEFAULT_ROUTE_PATH);
        $routePath = is_string($routePathSetting) ? trim($routePathSetting, '/') : self::DEFAULT_ROUTE_PATH;

        $sitePath = Environment::isCli() ? '/' : (string)GeneralUtility::getIndpEnv('TYPO3_SITE_PATH');

        return $sitePath . $routePath;
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
    private function buildSvgAttributes(array $arguments): string
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
