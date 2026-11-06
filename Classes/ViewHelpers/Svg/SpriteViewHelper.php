<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Svg;

use Maispace\MaiAssets\Collector\SvgSpriteCollector;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Gibt das komplette SVG-Sprite aus.
 *
 * Im Frontend hängt das der SvgSpriteInjectionListener an das Seiten-HTML.
 * Die Look-Vorschau rendert nur das Inhaltselement, deshalb muss das Sprite
 * dort explizit im Dokument stehen, sonst bleiben <use href="#…"> leer.
 */
class SpriteViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function __construct(
        private readonly SvgSpriteCollector $svgSpriteCollector,
    ) {}

    public function render(): string
    {
        return $this->svgSpriteCollector->build();
    }
}
