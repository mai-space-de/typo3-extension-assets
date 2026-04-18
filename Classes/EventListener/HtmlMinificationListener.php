<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use Maispace\MaiAssets\Service\HtmlMinificationService;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;

#[AsEventListener(
    identifier: 'mai-assets/html-minification',
    after: 'mai-assets/svg-sprite-injection',
)]
final class HtmlMinificationListener
{
    public function __construct(
        private readonly HtmlMinificationService $htmlMinificationService,
    ) {}

    public function __invoke(AfterCacheableContentIsGeneratedEvent $event): void
    {
        if (!$event->isCachingEnabled()) {
            return;
        }

        $html = $event->getContent();

        if ($html === '') {
            return;
        }

        $tsSettings = $event->getRequest()
            ->getAttribute('frontend.typoscript')
            ?->getSetupArray()['plugin.']['tx_maispace_assets.']['settings.']['htmlMinification.']
            ?? [];

        if (empty($tsSettings['enable'])) {
            return;
        }

        $event->setContent($this->htmlMinificationService->minify($html, $tsSettings));
    }
}
