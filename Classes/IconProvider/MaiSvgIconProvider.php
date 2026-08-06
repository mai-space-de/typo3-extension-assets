<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\IconProvider;

use Maispace\MaiAssets\Collector\SvgSpriteCollector;
use Maispace\MaiAssets\Traits\FileResolutionTrait;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconProvider\AbstractSvgIconProvider;

#[Autoconfigure(public: true)]
class MaiSvgIconProvider extends AbstractSvgIconProvider
{
    use FileResolutionTrait;

    public function __construct(
        private readonly SvgSpriteCollector $svgSpriteCollector,
    ) {}

    protected function generateMarkup(Icon $icon, array $options): string
    {
        if (empty($options['source'])) {
            throw new \InvalidArgumentException(
                '[' . $icon->getIdentifier() . '] The option "source" is required and must not be empty'
            );
        }

        $identifier = $options['identifier'] ?? $icon->getIdentifier();
        $source = (string)$options['source'];

        $resolvedPath = $this->resolveFilePath($source);
        if ($resolvedPath !== '' && file_exists($resolvedPath)) {
            $this->svgSpriteCollector->register($identifier, $resolvedPath);
        }

        return '<svg aria-hidden="true" focusable="false"'
            . ' width="' . $icon->getDimension()->getWidth() . '"'
            . ' height="' . $icon->getDimension()->getHeight() . '">'
            . '<use href="#' . htmlspecialchars($identifier, ENT_QUOTES) . '"/>'
            . '</svg>';
    }

    protected function generateInlineMarkup(array $options): string
    {
        if (empty($options['source'])) {
            throw new \InvalidArgumentException('The option "source" is required and must not be empty');
        }

        $resolvedPath = $this->resolveFilePath((string)$options['source']);
        if ($resolvedPath === '' || !file_exists($resolvedPath)) {
            return '';
        }

        $svgContent = (string)file_get_contents($resolvedPath);
        $svgContent = preg_replace('/<\?xml[^?]*\?>\s*/i', '', $svgContent) ?? $svgContent;
        $svgContent = preg_replace('/\s(width|height)="[^"]*"/i', '', $svgContent) ?? $svgContent;

        return $svgContent;
    }
}
