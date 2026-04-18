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
        // Only process cacheable responses
        if (!$event->isCachingEnabled()) {
            return;
        }

        // Guard: only minify text/html responses
        $response = $event->getResponse();
        $contentType = $response->getHeaderLine('Content-Type');
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return;
        }

        // Read TypoScript settings
        $tsSettings = $event->getRequest()
            ->getAttribute('frontend.typoscript')
            ?->getSetupArray()['plugin.']['tx_maispace_assets.']['settings.']['htmlMinification.']
            ?? [];

        if (empty($tsSettings['enable'])) {
            return;
        }

        $controller = $event->getController();
        $html = $controller->content;

        if ($html === '') {
            return;
        }

        $controller->content = $this->htmlMinificationService->minify($html, $tsSettings);
    }
}
