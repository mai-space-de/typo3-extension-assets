<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Svg;

use Maispace\MaiAssets\Collector\SvgSpriteCollector;
use Maispace\MaiAssets\Traits\FileResolutionTrait;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class IconViewHelper extends AbstractViewHelper
{
    use FileResolutionTrait;

    protected $escapeOutput = false;

    public function __construct(
        private readonly SvgSpriteCollector $svgSpriteCollector,
    ) {}

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
        $identifier = (string) $this->arguments['identifier'];
        $source = (string) $this->arguments['source'];
        $label = (string) $this->arguments['label'];
        $class = (string) $this->arguments['class'];
        $size = (string) $this->arguments['size'];

        // Resolve and register SVG file
        $resolvedPath = $this->resolveFilePath($source);
        if ($resolvedPath !== '' && file_exists($resolvedPath)) {
            $this->svgSpriteCollector->register($identifier, $resolvedPath);
        }

        // Non-cached parts (USER_INT) render after the page sprite was built, or on a page
        // cache hit where no sprite is built at all: deliver the symbol with the icon,
        // once per request.
        $lateSymbol = $this->rendersOutsideTheSprite() && !$this->svgSpriteCollector->isDelivered($identifier)
            ? $this->svgSpriteCollector->buildSymbols([$identifier])
            : '';

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
            return $lateSymbol . '<svg' . $attrs . $roleAttr . '>'
                . '<title>' . htmlspecialchars($label, ENT_QUOTES) . '</title>'
                . '<use href="#' . htmlspecialchars($identifier, ENT_QUOTES) . '"/>'
                . '</svg>';
        }

        // Decorative icon
        return $lateSymbol . '<svg' . $attrs . ' aria-hidden="true" focusable="false">'
            . '<use href="#' . htmlspecialchars($identifier, ENT_QUOTES) . '"/>'
            . '</svg>';
    }

    private function rendersOutsideTheSprite(): bool
    {
        if ($this->svgSpriteCollector->isBuilt()) {
            return true;
        }

        $request = method_exists($this->renderingContext, 'hasAttribute')
            && $this->renderingContext->hasAttribute(ServerRequestInterface::class)
            ? $this->renderingContext->getAttribute(ServerRequestInterface::class)
            : ($GLOBALS['TYPO3_REQUEST'] ?? null);
        $pageParts = $request instanceof ServerRequestInterface ? $request->getAttribute('frontend.page.parts') : null;

        return is_object($pageParts)
            && method_exists($pageParts, 'hasPageContentBeenLoadedFromCache')
            && $pageParts->hasPageContentBeenLoadedFromCache();
    }
}
