<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use Maispace\MaiAssets\Event\AfterCriticalUidsUpdatedEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Cache\CacheManager;

#[AsEventListener(identifier: 'mai-assets/critical-cache-invalidation')]
final class CriticalCacheInvalidationListener
{
    public function __construct(private readonly CacheManager $cacheManager) {}

    public function __invoke(AfterCriticalUidsUpdatedEvent $event): void
    {
        $this->cacheManager->flushCachesByTag('pageId_' . $event->getPageUid());
    }
}
