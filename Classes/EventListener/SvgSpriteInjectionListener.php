<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use Maispace\MaiAssets\Collector\FontPreloadCollector;
use Maispace\MaiAssets\Collector\SvgSpriteCollector;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;

#[AsEventListener(identifier: 'mai-assets/svg-sprite-injection')]
final class SvgSpriteInjectionListener
{
    public function __construct(
        private readonly SvgSpriteCollector $svgSpriteCollector,
        private readonly FontPreloadCollector $fontPreloadCollector,
    ) {}

    public function __invoke(AfterCacheableContentIsGeneratedEvent $event): void
    {
        $content = $event->getContent();

        // Inject SVG sprite immediately after <body> opening tag
        $sprite = $this->svgSpriteCollector->build();
        if ($sprite !== '') {
            $content = (string)preg_replace(
                '/<body([^>]*)>/i',
                '<body$1>' . $sprite,
                $content,
                1
            );
        }

        // Inject font preload links into <head>
        $fontLinks = $this->fontPreloadCollector->build();
        if ($fontLinks !== '') {
            $content = str_ireplace(
                '</head>',
                $fontLinks . '</head>',
                $content
            );
        }

        $event->setContent($content);
    }
}
