<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Event\BeforeObserverScriptInjectedEvent;
use Maispace\MaiAssets\Security\AboveFoldTokenService;
use Maispace\MaiAssets\Service\AboveFoldObserverScriptBuilder;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;

/**
 * Injects the above-fold IntersectionObserver as an inline script before
 * {@code </body>}. The script template is minified when asset minification is
 * enabled — HTML minification leaves {@code <script>} contents untouched.
 */
#[AsEventListener(
    identifier: 'mai-assets/above-fold-observer',
    before: 'mai-assets/html-minification',
)]
final readonly class AboveFoldObserverListener
{
    public function __construct(
        private AboveFoldCacheService $aboveFoldCacheService,
        private AboveFoldTokenService $tokenService,
        private EventDispatcherInterface $eventDispatcher,
        private ExtensionConfiguration $extensionConfiguration,
        private AboveFoldObserverScriptBuilder $scriptBuilder,
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
        if ($resetTimestamp === 0) {
            $resetTimestamp = time();
        }
        $token = $this->tokenService->generate($pageUid, $resetTimestamp);

        $validBuckets = (string)json_encode(array_keys($this->extensionConfiguration->getViewportBuckets()));

        $observerScriptPath = GeneralUtility::getFileAbsFileName(
            'EXT:mai_assets/Resources/Public/JavaScript/AboveFoldObserver.js'
        );

        if (!file_exists($observerScriptPath)) {
            return;
        }

        $scriptTemplate = (string)file_get_contents($observerScriptPath);
        $script = $this->scriptBuilder->build(
            $scriptTemplate,
            $pageUid,
            $resetTimestamp,
            $token,
            $validBuckets,
        );

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
