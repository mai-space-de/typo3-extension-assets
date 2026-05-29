<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Event;

use Maispace\MaiAssets\Cache\InvalidationService;
use Maispace\MaiAssets\Event\AfterCacheInvalidationEvent;
use Maispace\MaiAssets\Event\BeforeCacheInvalidationEvent;
use PHPUnit\Framework\TestCase;

final class AfterCacheInvalidationEventTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $event = new AfterCacheInvalidationEvent(
            BeforeCacheInvalidationEvent::TRIGGER_CONTENT_SAVE,
            42,
            [InvalidationService::TARGET_PAGE_CACHE, InvalidationService::TARGET_EARLY_HINTS]
        );

        $this->assertSame(BeforeCacheInvalidationEvent::TRIGGER_CONTENT_SAVE, $event->getTrigger());
        $this->assertSame(42, $event->getPageUid());
        $this->assertSame(
            [InvalidationService::TARGET_PAGE_CACHE, InvalidationService::TARGET_EARLY_HINTS],
            $event->getInvalidatedTargets()
        );
    }

    public function testInvalidatedTargetsCanBeEmpty(): void
    {
        $event = new AfterCacheInvalidationEvent(
            BeforeCacheInvalidationEvent::TRIGGER_BUCKET_UPDATE,
            1,
            []
        );

        $this->assertSame([], $event->getInvalidatedTargets());
    }

    public function testTriggerValuesAreCorrect(): void
    {
        $contentSaveEvent = new AfterCacheInvalidationEvent(
            BeforeCacheInvalidationEvent::TRIGGER_CONTENT_SAVE,
            1,
            []
        );

        $bucketUpdateEvent = new AfterCacheInvalidationEvent(
            BeforeCacheInvalidationEvent::TRIGGER_BUCKET_UPDATE,
            1,
            []
        );

        $this->assertSame('content_save', $contentSaveEvent->getTrigger());
        $this->assertSame('bucket_update', $bucketUpdateEvent->getTrigger());
    }
}
