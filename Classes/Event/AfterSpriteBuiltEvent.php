<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Event;

/**
 * Dispatched once after all registered SVG symbols have been assembled into a
 * sprite XML document, but before the result is stored in the cache and served
 * via the HTTP endpoint.
 *
 * Listeners can:
 *  - Modify the sprite XML via setSpriteXml() (e.g. append static symbols,
 *    add SVG `<defs>` for gradients or filters, prettify the output)
 *  - Inspect which symbols were included via getRegisteredSymbolIds()
 *
 * The modified XML returned by listeners is what gets cached and served.
 *
 * Registration example in your site package's Services.yaml:
 *
 *   MyVendor\MySitePackage\EventListener\MySpritePostProcessListener:
 *       tags:
 *           -   name: event.listener
 *               identifier: 'my-site-sprite-post-process'
 *               event: Maispace\MaispaceAssets\Event\AfterSpriteBuiltEvent
 *
 * @see \Maispace\MaispaceAssets\Registry\SpriteIconRegistry
 */
final class AfterSpriteBuiltEvent
{
    /**
     * @param string   $spriteXml           The assembled SVG sprite XML document
     * @param string[] $registeredSymbolIds All symbol IDs included in the sprite
     */
    public function __construct(
        private string $spriteXml,
        private readonly array $registeredSymbolIds,
    ) {
    }

    /**
     * The full SVG sprite XML document, e.g.:
     *
     *   <svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">
     *       <symbol id="icon-arrow" viewBox="0 0 24 24">...</symbol>
     *       <symbol id="icon-close" viewBox="0 0 24 24">...</symbol>
     *   </svg>
     */
    public function getSpriteXml(): string
    {
        return $this->spriteXml;
    }

    /**
     * Replace the sprite XML that will be cached and served from the HTTP endpoint.
     * Ensure the replacement is valid SVG.
     */
    public function setSpriteXml(string $spriteXml): void
    {
        $this->spriteXml = $spriteXml;
    }

    /**
     * The symbol IDs of all icons that were successfully included in the sprite.
     * Icons whose SVG source could not be read are silently omitted and not listed here.
     *
     * @return string[]
     */
    public function getRegisteredSymbolIds(): array
    {
        return $this->registeredSymbolIds;
    }
}
