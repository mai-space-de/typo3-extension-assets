<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\EventListener;

use Maispace\MaiAssets\Event\AfterCriticalUidsUpdatedEvent;
use Maispace\MaiAssets\EventListener\CacheWarmupListener;
use Maispace\MaiAssets\Service\CacheWarmupService;
use PHPUnit\Framework\TestCase;

final class CacheWarmupListenerTest extends TestCase
{
    public function testInvokeDelegatesToCacheWarmupServiceWithPageUid(): void
    {
        $cacheWarmupService = $this->createMock(CacheWarmupService::class);
        $cacheWarmupService
            ->expects(self::once())
            ->method('warmupPage')
            ->with(42);

        $event = new AfterCriticalUidsUpdatedEvent(42, 'desktop', [], [1, 2]);

        $listener = new CacheWarmupListener($cacheWarmupService);
        $listener($event);
    }

    public function testInvokePassesCorrectPageUidForDifferentPage(): void
    {
        $cacheWarmupService = $this->createMock(CacheWarmupService::class);
        $cacheWarmupService
            ->expects(self::once())
            ->method('warmupPage')
            ->with(7);

        $event = new AfterCriticalUidsUpdatedEvent(7, 'mobile', [3], [3, 4]);

        $listener = new CacheWarmupListener($cacheWarmupService);
        $listener($event);
    }

    public function testInvokePassesPageUidForTabletBucket(): void
    {
        $cacheWarmupService = $this->createMock(CacheWarmupService::class);
        $cacheWarmupService
            ->expects(self::once())
            ->method('warmupPage')
            ->with(99);

        $event = new AfterCriticalUidsUpdatedEvent(99, 'tablet', [1, 2], [2, 3]);

        $listener = new CacheWarmupListener($cacheWarmupService);
        $listener($event);
    }

    public function testInvokeSkipsWhenEventHasNoUids(): void
    {
        $cacheWarmupService = $this->createMock(CacheWarmupService::class);
        $cacheWarmupService
            ->expects(self::once())
            ->method('warmupPage')
            ->with(42);

        $event = new AfterCriticalUidsUpdatedEvent(42, 'mobile', [], []);

        $listener = new CacheWarmupListener($cacheWarmupService);
        $listener($event);
    }
}
