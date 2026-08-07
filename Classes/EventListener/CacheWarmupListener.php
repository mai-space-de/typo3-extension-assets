<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use Maispace\MaiAssets\Event\AfterCriticalUidsUpdatedEvent;
use Maispace\MaiAssets\Service\CacheWarmupService;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * When a viewport bucket's critical UIDs are updated and the page becomes
 * fully optimisation-ready, enqueue all language variants for static
 * file cache warming.
 *
 * This closes the loop: observer data arrives → readiness gating allows
 * caching → warm-up request renders the page → static file is written
 * and the early-hints manifest is stored.
 */
#[AsEventListener(identifier: 'mai-assets/cache-warmup')]
final readonly class CacheWarmupListener
{
    public function __construct(
        private CacheWarmupService $cacheWarmupService,
    ) {}

    public function __invoke(AfterCriticalUidsUpdatedEvent $event): void
    {
        $this->cacheWarmupService->warmupPage($event->getPageUid());
    }
}
