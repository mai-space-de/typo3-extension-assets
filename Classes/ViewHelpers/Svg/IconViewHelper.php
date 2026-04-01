<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Svg;

use Maispace\MaiAssets\Collector\SvgSpriteCollector;
use Maispace\MaiAssets\Traits\FileResolutionTrait;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

final class IconViewHelper extends AbstractViewHelper
{
    use FileResolutionTrait;

    protected $escapeOutput = false;

    public function __construct(
        private readonly SvgSpriteCollector $svgSpriteCollector,
    ) {
        parent::__construct();
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('identifier', 'string', 'Unique icon identifier', true);
        $this->registerArgument('source', 'string', 'EXT: path to SVG file', true);
        $this->registerArgument('label', 'string', 'aria-label for meaningful icons', false, '');
        $this->registerArgument('class', 'string', 'CSS class for the SVG element', false, '');
        $this->registerArgument('size', 'string', 'CSS size value', false, '1em');
    }

    public function render(): string
    {
        $identifier = (string)$this->arguments['identifier'];
        $source = (string)$this->arguments['source'];
        $label = (string)$this->arguments['label'];
        $class = (string)$this->arguments['class'];
        $size = (string)$this->arguments['size'];

        // Resolve and register SVG file
        $resolvedPath = $this->resolveFilePath($source);
        if ($resolvedPath !== '' && file_exists($resolvedPath)) {
            $this->svgSpriteCollector->register($identifier, $resolvedPath);
        }

        // Build attributes
        $attrs = '';

        if ($class !== '') {
            $attrs .= ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"';
        }

        if ($size !== '') {
            $attrs .= ' style="width:' . htmlspecialchars($size, ENT_QUOTES)
                . ';height:' . htmlspecialchars($size, ENT_QUOTES) . '"';
        }

        if ($label !== '') {
            // Meaningful icon: provide accessible label
            $roleAttr = ' role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '"';
            return '<svg' . $attrs . $roleAttr . '>'
                . '<title>' . htmlspecialchars($label, ENT_QUOTES) . '</title>'
                . '<use href="#' . htmlspecialchars($identifier, ENT_QUOTES) . '"/>'
                . '</svg>';
        }

        // Decorative icon
        return '<svg' . $attrs . ' aria-hidden="true" focusable="false">'
            . '<use href="#' . htmlspecialchars($identifier, ENT_QUOTES) . '"/>'
            . '</svg>';
    }
}
