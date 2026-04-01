<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Hook;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Event\BeforeObserverScriptInjectedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

final class AboveFoldObserverHook
{
    public function __construct(
        private readonly AboveFoldCacheService $aboveFoldCacheService,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function execute(array &$params, TypoScriptFrontendController $tsfe): void
    {
        // Only inject on cacheable pages
        if ($tsfe->no_cache) {
            return;
        }

        $pageUid = (int)$tsfe->id;
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

        $event = new BeforeObserverScriptInjectedEvent($script);
        $this->eventDispatcher->dispatch($event);

        if ($event->isCancelled()) {
            return;
        }

        $finalScript = $event->getScript();

        // Inject before </body>
        $params['pObj']->content = str_ireplace(
            '</body>',
            $finalScript . '</body>',
            $params['pObj']->content
        );
    }
}
