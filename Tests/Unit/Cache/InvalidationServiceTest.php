<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Cache;

use Maispace\MaiAssets\Cache\InvalidationService;
use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Event\AfterCacheInvalidationEvent;
use Maispace\MaiAssets\Event\BeforeCacheInvalidationEvent;
use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final class InvalidationServiceTest extends TestCase
{
    /** @var CacheManager&\PHPUnit\Framework\MockObject\MockObject */
    private CacheManager $cacheManager;
    /** @var EventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject */
    private EventDispatcherInterface $eventDispatcher;
    /** @var FrontendInterface&\PHPUnit\Framework\MockObject\MockObject */
    private FrontendInterface $aboveFoldFrontend;
    /** @var StaticFileRemovalService&\PHPUnit\Framework\MockObject\MockObject */
    private StaticFileRemovalService $staticFileRemovalService;
    private AboveFoldCacheService $aboveFoldCacheService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheManager = $this->createMock(CacheManager::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->aboveFoldFrontend = $this->createMock(FrontendInterface::class);
        $this->staticFileRemovalService = $this->createMock(StaticFileRemovalService::class);

        $this->cacheManager
            ->method('getCache')
            ->with('mai_assets_above_fold')
            ->willReturn($this->aboveFoldFrontend);

        $this->aboveFoldCacheService = new AboveFoldCacheService(
            $this->cacheManager,
            $this->eventDispatcher,
            $this->staticFileRemovalService,
        );
    }

    private function createService(): InvalidationService
    {
        return new InvalidationService(
            $this->cacheManager,
            $this->eventDispatcher,
            $this->aboveFoldCacheService,
            $this->staticFileRemovalService,
        );
    }

    public function testInvalidateAfterContentSavePositionFieldsClearsAboveFold(): void
    {
        $this->cacheManager->method('flushCachesByTag');
        $this->aboveFoldFrontend->expects(self::once())->method('remove')->with('buckets_42');
        $this->aboveFoldFrontend->expects(self::once())->method('set')->with(
            'reset_42', self::anything(), self::anything()
        );
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $this->staticFileRemovalService
            ->expects(self::exactly(3))
            ->method('purgeByPageUid')
            ->with(42);

        $this->createService()->invalidateAfterContentSave(42, ['sorting']);
    }

    public function testInvalidateAfterContentSaveNonPositionFieldsSkipsAboveFold(): void
    {
        $this->cacheManager->method('flushCachesByTag');
        $this->aboveFoldFrontend->expects(self::never())->method('remove');
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $this->staticFileRemovalService
            ->expects(self::once())
            ->method('purgeByPageUid')
            ->with(42);

        $this->createService()->invalidateAfterContentSave(42, ['bodytext']);
    }

    public function testInvalidateAfterBucketUpdateDelegatesCorrectly(): void
    {
        $this->cacheManager->expects(self::once())->method('flushCachesByTag')->with('pageId_7');
        $this->aboveFoldFrontend->expects(self::never())->method('remove');
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $this->staticFileRemovalService
            ->expects(self::once())
            ->method('purgeByPageUid')
            ->with(7);

        $this->createService()->invalidateAfterBucketUpdate(7, 'mobile');
    }

    public function testDispatchesBeforeAndAfterEvents(): void
    {
        $this->cacheManager->method('flushCachesByTag');
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

        $matcher = self::exactly(2);
        $this->eventDispatcher
            ->expects($matcher)
            ->method('dispatch')
            ->willReturnCallback(function (object $event) use ($matcher): object {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertInstanceOf(BeforeCacheInvalidationEvent::class, $event),
                    2 => $this->assertInstanceOf(AfterCacheInvalidationEvent::class, $event),
                };
                return $event;
            });

        $this->createService()->invalidateAfterContentSave(1, ['header']);
    }

    public function testBeforeEventAllowsClearingAllTargets(): void
    {
        $this->cacheManager->expects(self::never())->method('flushCachesByTag');
        $this->aboveFoldFrontend->expects(self::never())->method('remove');
        $this->staticFileRemovalService
            ->expects(self::never())
            ->method('purgeByPageUid');

        $this->eventDispatcher
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (object $event): object {
                if ($event instanceof BeforeCacheInvalidationEvent) {
                    $event->setTargets([]);
                }
                return $event;
            });

        $this->createService()->invalidateAfterContentSave(1, ['sorting']);
    }

    public function testAfterEventReportsInvalidatedTargets(): void
    {
        $this->cacheManager->method('flushCachesByTag');
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

        $this->eventDispatcher
            ->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (object $event): object {
                if ($event instanceof AfterCacheInvalidationEvent) {
                    $targets = $event->getInvalidatedTargets();
                    $this->assertContains(InvalidationService::TARGET_PAGE_CACHE, $targets);
                    $this->assertContains(InvalidationService::TARGET_EARLY_HINTS, $targets);
                    $this->assertContains(InvalidationService::TARGET_STATIC_FILE, $targets);
                }
                return $event;
            });

        $this->createService()->invalidateAfterContentSave(1, ['header']);
    }

    public function testContextContainsChangedFields(): void
    {
        $this->cacheManager->method('flushCachesByTag');
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

        $this->eventDispatcher
            ->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(function (object $event): object {
                if ($event instanceof BeforeCacheInvalidationEvent) {
                    $this->assertSame(['sorting', 'colPos'], $event->getContext()['changedFields']);
                }
                return $event;
            });

        $this->createService()->invalidateAfterContentSave(1, ['sorting', 'colPos']);
    }

    public function testContextContainsBucketForBucketUpdate(): void
    {
        $this->cacheManager->method('flushCachesByTag');
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

        $this->eventDispatcher
            ->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(function (object $event): object {
                if ($event instanceof BeforeCacheInvalidationEvent) {
                    $this->assertSame('tablet', $event->getContext()['bucket']);
                }
                return $event;
            });

        $this->createService()->invalidateAfterBucketUpdate(1, 'tablet');
    }

    public function testCacheManagerExceptionIsGraceful(): void
    {
        $this->cacheManager
            ->method('flushCachesByTag')
            ->willThrowException(new \RuntimeException('Cache unavailable'));
        $this->staticFileRemovalService
            ->method('purgeByPageUid')
            ->willThrowException(new \RuntimeException('Static cache unavailable'));

        $this->eventDispatcher
            ->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(function (object $event): object {
                if ($event instanceof AfterCacheInvalidationEvent) {
                    $this->assertSame([], $event->getInvalidatedTargets());
                }
                return $event;
            });

        $this->createService()->invalidateAfterContentSave(1, ['header']);
    }
}
