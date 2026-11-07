<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;

/**
 * Must run before the static HTML writer, which requires the manifest to exist
 * for the page/language combination it is about to persist.
 */
#[AsEventListener(
    identifier: 'mai-assets/early-hint-manifest',
    before: 'mai-assets/static-html-writer',
)]
final readonly class EarlyHintManifestListener
{
    public function __construct(
        private EarlyHintCandidateCollector $collector,
        private EarlyHintCacheService $cacheService,
    ) {}

    public function __invoke(AfterCacheableContentIsGeneratedEvent $event): void
    {
        if ($this->collector->isEmpty()) {
            return;
        }

        $request = $event->getRequest();
        $pageArguments = $request->getAttribute('routing');
        $language = $request->getAttribute('language');

        if ($pageArguments === null) {
            return;
        }

        $pageUid = (int) $pageArguments->getPageId();
        $languageUid = $language !== null ? (int) $language->getLanguageId() : 0;

        $this->cacheService->store($pageUid, $languageUid, $this->collector);
    }
}
