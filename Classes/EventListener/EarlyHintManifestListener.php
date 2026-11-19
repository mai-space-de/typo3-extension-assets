<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\StaticFileCache\StaticCacheRequestGuard;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;

/**
 * Stores the early hints collected while rendering, per page, language and URL
 * variant; EarlyHintsMiddleware and the static delivery send them as Link headers.
 */
#[AsEventListener(identifier: 'mai-assets/early-hint-manifest')]
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

        // Keyed by page, language and URL variant: a news detail page gets its own hints.
        $variant = StaticCacheRequestGuard::variantOf($pageArguments);
        if ($variant === null) {
            return;
        }

        $pageUid = (int) $pageArguments->getPageId();
        $languageUid = $language !== null ? (int) $language->getLanguageId() : 0;

        $this->cacheService->store($pageUid, $languageUid, $this->collector, $variant);
    }
}
