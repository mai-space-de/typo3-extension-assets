<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\EventListener;

use Maispace\MaiAssets\Event\AfterCriticalUidsUpdatedEvent;
use Maispace\MaiAssets\EventListener\CriticalCacheInvalidationListener;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;

final class CriticalCacheInvalidationListenerTest extends TestCase
{
    public function testInvokeFlushesPageCacheTagForAffectedPage(): void
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager
            ->expects(self::once())
            ->method('flushCachesByTag')
            ->with('pageId_42');

        $event = new AfterCriticalUidsUpdatedEvent(42, 'desktop', [], [1, 2]);

        $listener = new CriticalCacheInvalidationListener($cacheManager);
        $listener($event);
    }

    public function testInvokeUsesCorrectPageUidFromEvent(): void
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager
            ->expects(self::once())
            ->method('flushCachesByTag')
            ->with('pageId_7');

        $event = new AfterCriticalUidsUpdatedEvent(7, 'mobile', [3], [3, 4]);

        $listener = new CriticalCacheInvalidationListener($cacheManager);
        $listener($event);
    }
}
