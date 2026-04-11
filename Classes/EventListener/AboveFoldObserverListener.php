<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Event\BeforeObserverScriptInjectedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;

#[AsEventListener(identifier: 'mai-assets/above-fold-observer')]
final class AboveFoldObserverListener
{
    public function __construct(
        private readonly AboveFoldCacheService $aboveFoldCacheService,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function __invoke(AfterCacheableContentIsGeneratedEvent $event): void
    {
        // Only inject on cacheable pages
        if (!$event->isCachingEnabled()) {
            return;
        }

        $request = $event->getRequest();
        $pageUid = (int)($request->getAttribute('routing')?->getPageId() ?? 0);
        if ($pageUid <= 0) {
            return;
        }

        $resetTimestamp = $this->aboveFoldCacheService->getResetTimestamp($pageUid);

        $observerScriptPath = GeneralUtility::getFileAbsFileName(
            'EXT:mai_assets/Resources/Public/JavaScript/AboveFoldObserver.js'
        );

        if (!file_exists($observerScriptPath)) {
            return;
        }

        $scriptTemplate = (string)file_get_contents($observerScriptPath);
        $script = str_replace(
            ['###PAGE_UID###', '###SERVER_RESET_TIMESTAMP###'],
            [(string)$pageUid, (string)$resetTimestamp],
            $scriptTemplate
        );

        $script = '<script>' . $script . '</script>';

        $innerEvent = new BeforeObserverScriptInjectedEvent($script);
        $this->eventDispatcher->dispatch($innerEvent);

        if ($innerEvent->isCancelled()) {
            return;
        }

        $finalScript = $innerEvent->getScript();

        $content = str_ireplace(
            '</body>',
            $finalScript . '</body>',
            $event->getContent()
        );

        $event->setContent($content);
    }
}
