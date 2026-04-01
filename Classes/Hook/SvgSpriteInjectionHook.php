<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Hook;

use Maispace\MaiAssets\Collector\FontPreloadCollector;
use Maispace\MaiAssets\Collector\SvgSpriteCollector;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

final class SvgSpriteInjectionHook
{
    public function __construct(
        private readonly SvgSpriteCollector $svgSpriteCollector,
        private readonly FontPreloadCollector $fontPreloadCollector,
    ) {}

    public function execute(array &$params, TypoScriptFrontendController $tsfe): void
    {
        $content = &$params['pObj']->content;

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
    }
}
