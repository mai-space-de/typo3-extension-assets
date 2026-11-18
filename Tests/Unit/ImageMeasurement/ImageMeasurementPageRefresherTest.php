<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\ImageMeasurement;

use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementPageRefresher;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementRepository;
use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;

final class ImageMeasurementPageRefresherTest extends TestCase
{
    public function testRefreshFlushesPageCacheAndStaticFileOncePerPage(): void
    {
        $flushed = [];
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('flushCachesByTag')->willReturnCallback(static function (string $tag) use (&$flushed): void {
            $flushed[] = $tag;
        });
        $purged = [];
        $staticFileRemoval = $this->createMock(StaticFileRemovalService::class);
        $staticFileRemoval->method('purgeByPageUid')->willReturnCallback(static function (int $pageUid) use (&$purged): void {
            $purged[] = $pageUid;
        });

        $subject = new ImageMeasurementPageRefresher($this->createMock(ImageMeasurementRepository::class), $cacheManager, $staticFileRemoval);

        self::assertSame(2, $subject->refresh([3, 7, 3, 0]));
        self::assertSame(['pageId_3', 'pageId_7'], $flushed);
        self::assertSame([3, 7], $purged);
    }

    public function testFailingStaticFilePurgeDoesNotStopRefreshing(): void
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->expects(self::exactly(2))->method('flushCachesByTag');
        $staticFileRemoval = $this->createMock(StaticFileRemovalService::class);
        $staticFileRemoval->method('purgeByPageUid')->willThrowException(new \RuntimeException('no dir', 1763600001));

        $subject = new ImageMeasurementPageRefresher($this->createMock(ImageMeasurementRepository::class), $cacheManager, $staticFileRemoval);

        self::assertSame(2, $subject->refresh([1, 2]));
    }

    public function testLookupsFailSoftWithoutPageTable(): void
    {
        $repository = $this->createMock(ImageMeasurementRepository::class);
        $repository->method('getPageUids')->willThrowException(new \RuntimeException('table missing', 1763600002));
        $repository->method('getPageUidsForElement')->willThrowException(new \RuntimeException('table missing', 1763600003));
        $repository->method('getAllPageUids')->willThrowException(new \RuntimeException('table missing', 1763600004));

        $subject = new ImageMeasurementPageRefresher(
            $repository,
            $this->createMock(CacheManager::class),
            $this->createMock(StaticFileRemovalService::class),
        );

        self::assertSame([], $subject->pagesOfSlot('s.hero'));
        self::assertSame([], $subject->pagesOfElement(5));
        self::assertSame([], $subject->allPages());
    }
}
