<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Event;

use Maispace\MaiAssets\Cache\InvalidationService;
use Maispace\MaiAssets\Event\BeforeCacheInvalidationEvent;
use PHPUnit\Framework\TestCase;

final class BeforeCacheInvalidationEventTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $event = new BeforeCacheInvalidationEvent(
            BeforeCacheInvalidationEvent::TRIGGER_CONTENT_SAVE,
            42,
            [InvalidationService::TARGET_PAGE_CACHE],
            ['changedFields' => ['header']]
        );

        $this->assertSame(BeforeCacheInvalidationEvent::TRIGGER_CONTENT_SAVE, $event->getTrigger());
        $this->assertSame(42, $event->getPageUid());
        $this->assertSame([InvalidationService::TARGET_PAGE_CACHE], $event->getTargets());
        $this->assertSame(['changedFields' => ['header']], $event->getContext());
    }

    public function testAddTargetAppends(): void
    {
        $event = new BeforeCacheInvalidationEvent(
            BeforeCacheInvalidationEvent::TRIGGER_CONTENT_SAVE,
            1,
            [InvalidationService::TARGET_PAGE_CACHE]
        );

        $event->addTarget(InvalidationService::TARGET_EARLY_HINTS);

        $this->assertSame(
            [InvalidationService::TARGET_PAGE_CACHE, InvalidationService::TARGET_EARLY_HINTS],
            $event->getTargets()
        );
    }

    public function testAddTargetDeduplicates(): void
    {
        $event = new BeforeCacheInvalidationEvent(
            BeforeCacheInvalidationEvent::TRIGGER_BUCKET_UPDATE,
            1,
            [InvalidationService::TARGET_PAGE_CACHE]
        );

        $event->addTarget(InvalidationService::TARGET_PAGE_CACHE);

        $this->assertCount(1, $event->getTargets());
    }

    public function testRemoveTarget(): void
    {
        $event = new BeforeCacheInvalidationEvent(
            BeforeCacheInvalidationEvent::TRIGGER_CONTENT_SAVE,
            1,
            [InvalidationService::TARGET_PAGE_CACHE, InvalidationService::TARGET_EARLY_HINTS, InvalidationService::TARGET_STATIC_FILE]
        );

        $event->removeTarget(InvalidationService::TARGET_EARLY_HINTS);

        $this->assertSame(
            [InvalidationService::TARGET_PAGE_CACHE, InvalidationService::TARGET_STATIC_FILE],
            $event->getTargets()
        );
    }

    public function testSetTargetsReplacesAll(): void
    {
        $event = new BeforeCacheInvalidationEvent(
            BeforeCacheInvalidationEvent::TRIGGER_CONTENT_SAVE,
            1,
            [InvalidationService::TARGET_PAGE_CACHE]
        );

        $event->setTargets([InvalidationService::TARGET_ABOVE_FOLD]);

        $this->assertSame([InvalidationService::TARGET_ABOVE_FOLD], $event->getTargets());
    }

    public function testAddExplanation(): void
    {
        $event = new BeforeCacheInvalidationEvent(
            BeforeCacheInvalidationEvent::TRIGGER_CONTENT_SAVE,
            1,
            []
        );

        $event->addExplanation('reason', 'Content changed');

        $this->assertSame(['reason' => 'Content changed'], $event->getExplanations());
    }

    public function testDefaultContextIsEmpty(): void
    {
        $event = new BeforeCacheInvalidationEvent(
            BeforeCacheInvalidationEvent::TRIGGER_CONTENT_SAVE,
            1,
            []
        );

        $this->assertSame([], $event->getContext());
    }
}
