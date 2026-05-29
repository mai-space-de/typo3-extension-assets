<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\EventListener;

use Maispace\MaiAssets\Cache\InvalidationService;
use Maispace\MaiAssets\Event\AfterCriticalUidsUpdatedEvent;
use Maispace\MaiAssets\EventListener\CriticalCacheInvalidationListener;
use PHPUnit\Framework\TestCase;

final class CriticalCacheInvalidationListenerTest extends TestCase
{
    public function testInvokeDelegatesToInvalidationService(): void
    {
        $invalidationService = $this->createMock(InvalidationService::class);
        $invalidationService
            ->expects(self::once())
            ->method('invalidateAfterBucketUpdate')
            ->with(42, 'desktop');

        $event = new AfterCriticalUidsUpdatedEvent(42, 'desktop', [], [1, 2]);

        $listener = new CriticalCacheInvalidationListener($invalidationService);
        $listener($event);
    }

    public function testInvokePassesCorrectPageUid(): void
    {
        $invalidationService = $this->createMock(InvalidationService::class);
        $invalidationService
            ->expects(self::once())
            ->method('invalidateAfterBucketUpdate')
            ->with(7, 'mobile');

        $event = new AfterCriticalUidsUpdatedEvent(7, 'mobile', [3], [3, 4]);

        $listener = new CriticalCacheInvalidationListener($invalidationService);
        $listener($event);
    }

    public function testInvokePassesCorrectBucket(): void
    {
        $invalidationService = $this->createMock(InvalidationService::class);
        $invalidationService
            ->expects(self::once())
            ->method('invalidateAfterBucketUpdate')
            ->with(99, 'tablet');

        $event = new AfterCriticalUidsUpdatedEvent(99, 'tablet', [1, 2], [2, 3]);

        $listener = new CriticalCacheInvalidationListener($invalidationService);
        $listener($event);
    }
}
