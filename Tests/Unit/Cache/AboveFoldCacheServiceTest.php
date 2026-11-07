<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Cache;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final class AboveFoldCacheServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $store = [];

    private AboveFoldCacheService $subject;

    /** @var EventDispatcherInterface&MockObject */
    private EventDispatcherInterface $eventDispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = [];
        $frontend = $this->createMock(FrontendInterface::class);
        $frontend->method('get')->willReturnCallback(fn(string $key): mixed => $this->store[$key] ?? false);
        $frontend->method('set')->willReturnCallback(function (string $key, mixed $data): void {
            $this->store[$key] = $data;
        });

        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($frontend);

        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->subject = new AboveFoldCacheService(
            $cacheManager,
            $this->eventDispatcher,
            $this->createMock(StaticFileRemovalService::class),
        );
    }

    public function testEmptyReportRegistersBucketAndReportsChange(): void
    {
        self::assertTrue($this->subject->updateCriticalUids(1, 'mobile', []));
        self::assertSame(['mobile'], $this->subject->getBucketNames(1));
    }

    public function testRepeatedIdenticalReportIsNoChange(): void
    {
        $this->subject->updateCriticalUids(1, 'desktop', [3, 1]);

        self::assertFalse($this->subject->updateCriticalUids(1, 'desktop', [1, 3]));
        self::assertSame(['desktop'], $this->subject->getBucketNames(1));
    }

    public function testChangedUidsAreReportedAsChange(): void
    {
        $this->subject->updateCriticalUids(1, 'desktop', [1]);

        self::assertTrue($this->subject->updateCriticalUids(1, 'desktop', [1, 2]));
        self::assertSame(['desktop'], $this->subject->getBucketNames(1));
    }
}
