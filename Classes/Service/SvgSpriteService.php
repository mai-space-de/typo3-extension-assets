<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\Service;

use Maispace\MaispaceAssets\Cache\AssetCacheManager;
use Maispace\MaispaceAssets\Event\AfterSvgSpriteBuiltEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Manages the per-request SVG sprite.
 *
 * Implements SingletonInterface so TYPO3's DI container returns the same instance
 * throughout a single page request. This allows SVG symbols to accumulate from
 * any number of partials and templates before being output as a single sprite.
 *
 * Typical template workflow:
 *
 *   1. Register symbols wherever SVGs are used (partials, loops, etc.):
 *      <ma:svgSprite register="EXT:theme/Resources/Public/Icons/arrow.svg" />
 *      <ma:svgSprite register="EXT:theme/Resources/Public/Icons/close.svg" symbolId="icon-close" />
 *
 *   2. Render the sprite once, at the top of <body> in the main layout:
 *      <ma:svgSprite render="true" />
 *
 *   3. Reference symbols anywhere in the template:
 *      <ma:svgSprite use="icon-arrow" width="24" height="24" class="icon" />
 *
 * The hidden sprite SVG block must appear in the DOM before any <use> reference.
 * Placing it at the very start of <body> is the recommended approach.
 *
 * Caching:
 *   The assembled sprite HTML is cached in the maispace_assets cache. The cache key
 *   is derived from the sorted list of registered symbol IDs, so the same set of icons
 *   always produces the same cached sprite — and a different set triggers a rebuild.
 */
final class SvgSpriteService implements SingletonInterface
{
    /** @var array<string, string> symbolId => <symbol> XML element string */
    private array $symbols = [];

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AssetCacheManager $cache,
    ) {}

    // -------------------------------------------------------------------------
    // Public API (called from SvgSpriteViewHelper)
    // -------------------------------------------------------------------------

    /**
     * Register an SVG file as a sprite symbol.
     *
     * Calling this multiple times with the same symbol ID is safe — subsequent
     * calls are silently ignored, so partials called from inside loops won't
     * add duplicate symbols.
     *
     * @param string      $src      EXT: path or absolute path to the .svg file
     * @param string|null $symbolId Symbol ID override. Auto-derived from filename if null.
     */
    public function registerSymbol(string $src, ?string $symbolId = null): void
    {
        $absolutePath = GeneralUtility::getFileAbsFileName($src);
        if ($absolutePath === '' || !is_file($absolutePath)) {
            // Log warning without crashing the page render.
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)
                ->getLogger(__CLASS__)
                ->warning('maispace_assets: SVG file not found: ' . $src);
            return;
        }

        $resolvedId = $this->resolveSymbolId($symbolId, $absolutePath);

        // Idempotent: skip if already registered.
        if (isset($this->symbols[$resolvedId])) {
            return;
        }

        $svgContent = (string)file_get_contents($absolutePath);
        $symbolXml  = $this->extractSymbol($svgContent, $resolvedId);

        if ($symbolXml !== '') {
            $this->symbols[$resolvedId] = $symbolXml;
        }
    }

    /**
     * Assemble and return the full hidden SVG sprite block.
     *
     * Call this once per page render, at the top of <body>.
     * Returns an empty string if no symbols have been registered.
     */
    public function renderSprite(): string
    {
        if ($this->symbols === []) {
            return '';
        }

        $cacheKey = $this->cache->buildSpriteKey(array_keys($this->symbols));

        if ($this->cache->has($cacheKey)) {
            return (string)$this->cache->get($cacheKey);
        }

        $spriteHtml = $this->buildSpriteHtml();

        /** @var AfterSvgSpriteBuiltEvent $event */
        $event = $this->eventDispatcher->dispatch(
            new AfterSvgSpriteBuiltEvent($spriteHtml, array_keys($this->symbols)),
        );
        $spriteHtml = $event->getSpriteHtml();

        $this->cache->set($cacheKey, $spriteHtml, ['maispace_assets_svg']);

        return $spriteHtml;
    }

    /**
     * Render a <svg><use> reference for a previously registered symbol.
     *
     * @param array $arguments The full ViewHelper arguments array (use, class, width, height, aria-*, title)
     */
    public function renderUseTag(array $arguments): string
    {
        $symbolId = (string)($arguments['use'] ?? '');
        if ($symbolId === '') {
            return '';
        }

        $attrs = [];

        if (!empty($arguments['class'])) {
            $attrs[] = 'class="' . htmlspecialchars((string)$arguments['class']) . '"';
        }
        if (!empty($arguments['width'])) {
            $attrs[] = 'width="' . htmlspecialchars((string)$arguments['width']) . '"';
        }
        if (!empty($arguments['height'])) {
            $attrs[] = 'height="' . htmlspecialchars((string)$arguments['height']) . '"';
        }

        // Accessibility attributes.
        $ariaHidden = $arguments['aria-hidden'] ?? null;
        $ariaLabel  = $arguments['aria-label'] ?? null;

        if ($ariaLabel !== null && $ariaLabel !== '') {
            // When a label is present, do not hide from assistive technology.
            $attrs[] = 'aria-label="' . htmlspecialchars((string)$ariaLabel) . '"';
            $attrs[] = 'role="img"';
        } elseif ($ariaHidden !== 'false') {
            // Default: decorative icon, hidden from screen readers.
            $attrs[] = 'aria-hidden="true"';
        }

        $attrString = $attrs !== [] ? ' ' . implode(' ', $attrs) : '';

        // Optional <title> for screen reader support when aria-label is not used.
        $titleTag = '';
        if (!empty($arguments['title'])) {
            $titleTag = '<title>' . htmlspecialchars((string)$arguments['title']) . '</title>';
        }

        return sprintf(
            '<svg%s>%s<use href="#%s"></use></svg>',
            $attrString,
            $titleTag,
            htmlspecialchars($symbolId),
        );
    }

    /**
     * Return all currently registered symbol IDs (useful for debugging/events).
     *
     * @return string[]
     */
    public function getRegisteredSymbolIds(): array
    {
        return array_keys($this->symbols);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Derive the symbol ID from an optional explicit override or the filename.
     *
     * Example: "arrow.svg" → configured prefix + "arrow" → "icon-arrow"
     */
    private function resolveSymbolId(?string $explicit, string $absolutePath): string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        $prefix   = (string)$this->getTypoScriptSetting('svgSprite.symbolIdPrefix', 'icon-');
        $basename = pathinfo($absolutePath, PATHINFO_FILENAME);

        return $prefix . $basename;
    }

    /**
     * Parse SVG file content and extract a <symbol> element.
     *
     * The outer <svg> element is stripped; its viewBox attribute is preserved on the
     * <symbol>. All child content is kept as-is. Comments and XML declarations are removed.
     */
    private function extractSymbol(string $svgContent, string $symbolId): string
    {
        // Strip XML declaration.
        $svgContent = preg_replace('/<\?xml[^?]*\?>/i', '', $svgContent) ?? $svgContent;

        // Strip doctype.
        $svgContent = preg_replace('/<!DOCTYPE[^>]*>/i', '', $svgContent) ?? $svgContent;

        // Strip HTML comments (but not IE conditionals, just in case).
        $svgContent = preg_replace('/<!--(?!\[if).*?-->/s', '', $svgContent) ?? $svgContent;

        $svgContent = trim($svgContent);

        // Extract viewBox from the outer <svg> element.
        $viewBox = '';
        if (preg_match('/<svg[^>]*\sviewBox=["\']([^"\']+)["\'][^>]*>/i', $svgContent, $m)) {
            $viewBox = $m[1];
        }

        // Extract the inner content of the <svg> element (everything between <svg ...> and </svg>).
        if (!preg_match('/<svg[^>]*>(.*)<\/svg>/is', $svgContent, $m)) {
            return '';
        }
        $innerContent = trim($m[1]);

        if ($innerContent === '') {
            return '';
        }

        $viewBoxAttr = $viewBox !== '' ? ' viewBox="' . htmlspecialchars($viewBox) . '"' : '';

        return sprintf(
            '<symbol id="%s"%s>%s</symbol>',
            htmlspecialchars($symbolId),
            $viewBoxAttr,
            $innerContent,
        );
    }

    /**
     * Assemble the hidden sprite SVG container from all registered symbols.
     */
    private function buildSpriteHtml(): string
    {
        $symbols = implode("\n", array_values($this->symbols));

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">%s</svg>',
            "\n" . $symbols . "\n",
        );
    }

    /**
     * Read a TypoScript setting from plugin.tx_maispace_assets.{dotPath}.
     */
    private function getTypoScriptSetting(string $dotPath, mixed $default): mixed
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request === null) {
            return $default;
        }

        /** @var \TYPO3\CMS\Core\TypoScript\FrontendTypoScript|null $frontendTypoScript */
        $frontendTypoScript = $request->getAttribute('frontend.typoscript');
        if ($frontendTypoScript === null) {
            return $default;
        }

        $setup = $frontendTypoScript->getSetupArray();
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
