<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\Registry;

use Maispace\MaispaceAssets\Cache\AssetCacheManager;
use Maispace\MaispaceAssets\Event\AfterSpriteBuiltEvent;
use Maispace\MaispaceAssets\Event\BeforeSpriteSymbolRegisteredEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Discovers, assembles, and caches the SVG sprite served by SvgSpriteMiddleware.
 *
 * Auto-discovery
 * ==============
 * On first use, the registry iterates all loaded TYPO3 extensions and looks for
 * `Configuration/SpriteIcons.php`. Any extension can contribute SVG symbols by
 * dropping this file — no `ext_localconf.php` registration required.
 *
 * File format (`EXT:my_ext/Configuration/SpriteIcons.php`):
 *
 *   <?php
 *   return [
 *       'icon-arrow' => ['src' => 'EXT:my_ext/Resources/Public/Icons/arrow.svg'],
 *       'icon-close' => ['src' => 'EXT:my_ext/Resources/Public/Icons/close.svg'],
 *   ];
 *
 * The array key is the symbol ID used in `<symbol id="...">` and `<use href="...#id">`.
 *
 * Events
 * ======
 * - `BeforeSpriteSymbolRegisteredEvent` — fired per symbol; listeners can rename, modify,
 *   or veto (skip) individual icons before they enter the registry.
 * - `AfterSpriteBuiltEvent` — fired once after all symbols are assembled; listeners can
 *   post-process the full sprite XML before it is cached.
 *
 * Caching
 * =======
 * The assembled sprite XML is stored in the `maispace_assets` caching-framework cache.
 * The cache key encodes every symbol's ID, resolved src path, and file modification time,
 * so any change to a source SVG file automatically busts the cache without a manual flush.
 * The cache belongs to the `pages` and `all` groups, so clearing the TYPO3 frontend
 * cache also invalidates the sprite.
 *
 * @see \Maispace\MaispaceAssets\Middleware\SvgSpriteMiddleware
 * @see \Maispace\MaispaceAssets\Event\BeforeSpriteSymbolRegisteredEvent
 * @see \Maispace\MaispaceAssets\Event\AfterSpriteBuiltEvent
 */
final class SpriteIconRegistry implements SingletonInterface
{
    private const CACHE_KEY_PREFIX = 'svg_api_sprite_';
    private const CACHE_TAG        = 'maispace_assets_svg';

    /** @var array<string, array{src: string, absoluteSrc: string}> symbolId => resolved config */
    private array $symbols = [];

    private bool $discovered = false;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AssetCacheManager $cache,
        private readonly LoggerInterface $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Build (or return from cache) the full SVG sprite XML document.
     *
     * The sprite is a hidden `<svg>` element containing one `<symbol>` per registered icon.
     * Returns an empty string if no symbols are registered.
     */
    public function buildSprite(): string
    {
        $this->discover();

        if ($this->symbols === []) {
            return '';
        }

        $cacheKey = $this->buildCacheKey();

        if ($this->cache->has($cacheKey)) {
            return (string)$this->cache->get($cacheKey);
        }

        $spriteXml = $this->assembleSpriteXml();

        /** @var AfterSpriteBuiltEvent $event */
        $event = $this->eventDispatcher->dispatch(
            new AfterSpriteBuiltEvent($spriteXml, array_keys($this->symbols)),
        );
        $spriteXml = $event->getSpriteXml();

        $this->cache->set($cacheKey, $spriteXml, [self::CACHE_TAG]);

        return $spriteXml;
    }

    /**
     * Return all symbol IDs that were successfully registered (after event filtering).
     * Triggers auto-discovery if not yet done.
     *
     * @return string[]
     */
    public function getRegisteredSymbolIds(): array
    {
        $this->discover();
        return array_keys($this->symbols);
    }

    // -------------------------------------------------------------------------
    // Auto-discovery
    // -------------------------------------------------------------------------

    /**
     * Scan all loaded extensions for `Configuration/SpriteIcons.php` and populate
     * the symbol registry. Idempotent — subsequent calls are no-ops.
     */
    private function discover(): void
    {
        if ($this->discovered) {
            return;
        }
        $this->discovered = true;

        foreach (ExtensionManagementUtility::getLoadedExtensionListArray() as $extKey) {
            $file = ExtensionManagementUtility::extPath($extKey) . 'Configuration/SpriteIcons.php';

            if (!is_file($file)) {
                continue;
            }

            $icons = require $file;

            if (!is_array($icons)) {
                $this->logger->warning(
                    'maispace_assets: SpriteIcons.php in extension "' . $extKey . '" did not return an array.',
                );
                continue;
            }

            foreach ($icons as $symbolId => $config) {
                if (!is_string($symbolId) || $symbolId === '') {
                    $this->logger->warning(
                        'maispace_assets: Skipping invalid symbol key in "' . $extKey . '/Configuration/SpriteIcons.php".',
                    );
                    continue;
                }

                if (!isset($config['src']) || !is_string($config['src'])) {
                    $this->logger->warning(
                        'maispace_assets: Symbol "' . $symbolId . '" in "' . $extKey . '" is missing required "src" key.',
                    );
                    continue;
                }

                /** @var BeforeSpriteSymbolRegisteredEvent $event */
                $event = $this->eventDispatcher->dispatch(
                    new BeforeSpriteSymbolRegisteredEvent($symbolId, $config, $extKey),
                );

                if ($event->isSkipped()) {
                    continue;
                }

                $resolvedSymbolId = $event->getSymbolId();
                $resolvedConfig   = $event->getConfig();

                $absoluteSrc = GeneralUtility::getFileAbsFileName($resolvedConfig['src']);
                if ($absoluteSrc === '' || !is_file($absoluteSrc)) {
                    $this->logger->warning(
                        'maispace_assets: SVG file not found for symbol "' . $resolvedSymbolId . '": ' . $resolvedConfig['src'],
                    );
                    continue;
                }

                // Later registrations win — allows site packages to override vendor icons.
                $this->symbols[$resolvedSymbolId] = array_merge($resolvedConfig, [
                    'absoluteSrc' => $absoluteSrc,
                ]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Sprite assembly
    // -------------------------------------------------------------------------

    /**
     * Read each registered SVG file, extract its `<symbol>` representation,
     * and wrap everything in the sprite container.
     */
    private function assembleSpriteXml(): string
    {
        $symbolBlocks = [];

        foreach ($this->symbols as $symbolId => $config) {
            $svgContent = @file_get_contents($config['absoluteSrc']);
            if ($svgContent === false) {
                $this->logger->error(
                    'maispace_assets: Could not read SVG file for symbol "' . $symbolId . '": ' . $config['absoluteSrc'],
                );
                continue;
            }

            $symbol = $this->extractSymbol($svgContent, $symbolId);
            if ($symbol !== '') {
                $symbolBlocks[] = '    ' . $symbol;
            }
        }

        if ($symbolBlocks === []) {
            return '';
        }

        return implode("\n", [
            '<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">',
            implode("\n", $symbolBlocks),
            '</svg>',
        ]);
    }

    /**
     * Parse SVG file content and return a `<symbol>` element string.
     *
     * - Strips XML declarations, DOCTYPE, and HTML comments.
     * - Extracts `viewBox` from the outer `<svg>` element.
     * - Wraps inner content in `<symbol id="..." viewBox="...">`.
     */
    private function extractSymbol(string $svgContent, string $symbolId): string
    {
        // Strip XML declaration and DOCTYPE.
        $svgContent = (string)preg_replace('/<\?xml[^?]*\?>/i', '', $svgContent);
        $svgContent = (string)preg_replace('/<!DOCTYPE[^>]*>/i', '', $svgContent);

        // Strip HTML comments (preserve IE conditionals just in case).
        $svgContent = (string)preg_replace('/<!--(?!\[if).*?-->/s', '', $svgContent);

        $svgContent = trim($svgContent);

        // Extract viewBox from the outer <svg> element.
        $viewBox = '';
        if (preg_match('/<svg[^>]*\sviewBox=["\']([^"\']+)["\'][^>]*>/i', $svgContent, $m)) {
            $viewBox = $m[1];
        }

        // Extract the inner content between <svg ...> and </svg>.
        if (!preg_match('/<svg[^>]*>(.*)<\/svg>/is', $svgContent, $m)) {
            $this->logger->warning('maispace_assets: Could not parse SVG structure for symbol "' . $symbolId . '".');
            return '';
        }

        $innerContent = trim($m[1]);
        if ($innerContent === '') {
            return '';
        }

        $viewBoxAttr = $viewBox !== '' ? ' viewBox="' . htmlspecialchars($viewBox, ENT_XML1) . '"' : '';

        return sprintf(
            '<symbol id="%s"%s>%s</symbol>',
            htmlspecialchars($symbolId, ENT_XML1),
            $viewBoxAttr,
            $innerContent,
        );
    }

    // -------------------------------------------------------------------------
    // Cache key
    // -------------------------------------------------------------------------

    /**
     * Build a cache key that encodes the identity of all registered symbols.
     *
     * The key includes each symbol's ID, its resolved file path, and the file
     * modification time. Any change to a source SVG (or the addition/removal of
     * a symbol) produces a different key — no manual cache flush required.
     */
    private function buildCacheKey(): string
    {
        $parts = [];
        foreach ($this->symbols as $symbolId => $config) {
            $mtime  = @filemtime($config['absoluteSrc']) ?: 0;
            $parts[] = $symbolId . '|' . $config['absoluteSrc'] . '|' . $mtime;
        }
        sort($parts);

        return self::CACHE_KEY_PREFIX . sha1(implode(',', $parts));
    }
}
