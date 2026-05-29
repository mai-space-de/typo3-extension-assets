<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use Maispace\MaiAssets\Cache\InvalidationService;
use Maispace\MaiAssets\Event\AfterCriticalUidsUpdatedEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

#[AsEventListener(identifier: 'mai-assets/critical-cache-invalidation')]
final class CriticalCacheInvalidationListener
{
    public function __construct(private readonly InvalidationService $invalidationService) {}

    public function __invoke(AfterCriticalUidsUpdatedEvent $event): void
    {
        $this->invalidationService->invalidateAfterBucketUpdate(
            $event->getPageUid(),
            $event->getBucket()
        );
    }
}
