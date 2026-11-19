<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\EventListener;

use Maispace\MaiAssets\EventListener\StaticFileCacheFlushListener;
use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Event\CacheFlushEvent;

final class StaticFileCacheFlushListenerTest extends TestCase
{
    public function testFlushingPagesOrAllRemovesStaticFiles(): void
    {
        $removal = $this->createMock(StaticFileRemovalService::class);
        $removal->expects(self::exactly(2))->method('purgeAll');
        $listener = new StaticFileCacheFlushListener($removal);

        $listener(new CacheFlushEvent(['pages']));
        $listener(new CacheFlushEvent(['all']));
    }

    public function testOtherGroupsKeepStaticFiles(): void
    {
        $removal = $this->createMock(StaticFileRemovalService::class);
        $removal->expects(self::never())->method('purgeAll');

        (new StaticFileCacheFlushListener($removal))(new CacheFlushEvent(['system', 'di']));
    }

    public function testErrorsAreReportedOnTheEvent(): void
    {
        $removal = $this->createMock(StaticFileRemovalService::class);
        $removal->method('purgeAll')->willThrowException(new \RuntimeException('read-only', 1763700002));
        $event = new CacheFlushEvent(['pages']);

        (new StaticFileCacheFlushListener($removal))($event);

        self::assertCount(1, $event->getErrors());
    }
}
