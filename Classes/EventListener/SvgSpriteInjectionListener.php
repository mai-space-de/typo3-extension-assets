<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use Maispace\MaiAssets\Collector\FontPreloadCollector;
use Maispace\MaiAssets\Collector\SvgSpriteCollector;
use Maispace\MaiAssets\Configuration\ExtensionConfigurationDiscovery;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidate;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;

#[AsEventListener(identifier: 'mai-assets/svg-sprite-injection')]
final class SvgSpriteInjectionListener
{
    public function __construct(
        private readonly SvgSpriteCollector $svgSpriteCollector,
        private readonly FontPreloadCollector $fontPreloadCollector,
        private readonly ExtensionConfigurationDiscovery $extensionConfigurationDiscovery,
        private readonly EarlyHintCandidateCollector $earlyHintCollector,
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

        // Inject preconnect links into <head>
        $preconnectLinks = '';
        foreach ($this->extensionConfigurationDiscovery->discoverPreconnects() as $origin) {
            $crossorigin = $origin['crossorigin'] !== '' ? $origin['crossorigin'] : 'anonymous';
            $preconnectLinks .= sprintf(
                '<link rel="preconnect" href="%s" crossorigin="%s">',
                htmlspecialchars($origin['href'], ENT_QUOTES),
                htmlspecialchars($crossorigin, ENT_QUOTES)
            ) . "\n";
            $this->earlyHintCollector->add(new EarlyHintCandidate(
                href: $origin['href'],
                rel: 'preconnect',
                crossorigin: $crossorigin,
            ));
        }

        $headInsert = $fontLinks . $preconnectLinks;
        if ($headInsert !== '') {
            $content = str_ireplace('</head>', $headInsert . '</head>', $content);
        }

        $event->setContent($content);
    }
}
