<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use Maispace\MaiAssets\Cache\InvalidationService;
use Maispace\MaiAssets\Event\AfterCriticalUidsUpdatedEvent;
use Maispace\MaiAssets\Service\PageOptimizationReadinessService;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * When observer data completes all viewport buckets, invalidate TYPO3 page
 * cache, early-hints manifest, and static HTML so the next request re-renders
 * with inlined critical CSS before static file cache write.
 */
#[AsEventListener(identifier: 'mai-assets/critical-cache-invalidation')]
final class CriticalCacheInvalidationListener
{
    public function __construct(
        private readonly InvalidationService $invalidationService,
        private readonly PageOptimizationReadinessService $readinessService,
    ) {}

    public function __invoke(AfterCriticalUidsUpdatedEvent $event): void
    {
        if (!$this->readinessService->isReady($event->getPageUid())) {
            return;
        }

        $this->invalidationService->invalidateAfterBucketUpdate(
            $event->getPageUid(),
            $event->getBucket()
        );
    }
}
