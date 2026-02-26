<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\Event;

/**
 * Dispatched after the SVG sprite HTML has been assembled from all registered symbols,
 * but before it is returned to the template by SvgSpriteViewHelper (render mode).
 *
 * Event listeners can:
 *  - Modify the sprite HTML via setSpriteHtml() (e.g., add accessibility attributes)
 *  - Inspect which symbols were included via getRegisteredSymbolIds()
 *  - Append additional static symbols not registered via ViewHelper
 *
 * Registration example in your site package's Services.yaml:
 *
 *   MyVendor\MySitePackage\EventListener\MySpriteListener:
 *       tags:
 *           -   name: event.listener
 *               identifier: 'my-site-svg-sprite'
 *               event: Maispace\MaispaceAssets\Event\AfterSvgSpriteBuiltEvent
 *
 * @see \Maispace\MaispaceAssets\Service\SvgSpriteService::renderSprite()
 */
final class AfterSvgSpriteBuiltEvent
{
    public function __construct(
        private string $spriteHtml,
        private readonly array $registeredSymbolIds,
    ) {}

    public function getSpriteHtml(): string
    {
        return $this->spriteHtml;
    }

    /**
     * Replace the SVG sprite HTML that will be output to the template.
     * The modified HTML is also stored in the cache.
     */
    public function setSpriteHtml(string $html): void
    {
        $this->spriteHtml = $html;
    }

    /**
     * List of all symbol IDs that were registered before renderSprite() was called.
     * The order matches the order in which <ma:svgSprite register="..."> calls were
     * encountered during this page render.
     *
     * @return string[]
     */
    public function getRegisteredSymbolIds(): array
    {
        return $this->registeredSymbolIds;
    }
}
