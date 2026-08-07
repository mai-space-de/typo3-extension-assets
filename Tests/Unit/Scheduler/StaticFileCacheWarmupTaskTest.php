<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Scheduler;

use Maispace\MaiAssets\Scheduler\StaticFileCacheWarmupTask;
use Maispace\MaiAssets\Service\WarmupHttpClientService;
use Maispace\MaiAssets\StaticFileCache\WarmupQueueRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * StaticFileCacheWarmupTask is instantiated by the scheduler without DI
 * (task records are unserialized from the database), so it resolves its
 * collaborators via GeneralUtility::makeInstance(). Tests inject mocks the
 * same way TYPO3 itself does: GeneralUtility::addInstance().
 */
final class StaticFileCacheWarmupTaskTest extends TestCase
{
    private StaticFileCacheWarmupTask $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->task = new StaticFileCacheWarmupTask();
        $this->task->setLogger(new NullLogger());
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    public function testExecuteReturnsTrueWhenQueueIsEmpty(): void
    {
        $queueRepository = $this->createMock(WarmupQueueRepository::class);
        $queueRepository->method('countOpen')->willReturn(0);
        $queueRepository->expects(self::never())->method('findOpenBatch');

        GeneralUtility::addInstance(WarmupQueueRepository::class, $queueRepository);
        GeneralUtility::addInstance(WarmupHttpClientService::class, $this->createMock(WarmupHttpClientService::class));

        self::assertTrue($this->task->execute());
    }

    public function testExecuteProcessesOpenQueueAndCleansUp(): void
    {
        $queueRepository = $this->createMock(WarmupQueueRepository::class);
        $queueRepository->method('countOpen')->willReturn(2);
        $queueRepository
            ->expects(self::once())
            ->method('findOpenBatch')
            ->with(50, 0)
            ->willReturn([
                ['uid' => 1, 'cache_url' => 'https://example.test/a'],
                ['uid' => 2, 'cache_url' => 'https://example.test/b'],
            ]);
        $queueRepository
            ->expects(self::once())
            ->method('markProcessed')
            ->with([
                ['uid' => 1, 'call_result' => 200],
                ['uid' => 2, 'call_result' => 404],
            ]);
        $queueRepository->expects(self::once())->method('cleanupOld')->with(1000)->willReturn(0);

        $httpClientService = $this->createMock(WarmupHttpClientService::class);
        $httpClientService
            ->method('runBatch')
            ->with([1 => 'https://example.test/a', 2 => 'https://example.test/b'], 10)
            ->willReturn([1 => 200, 2 => 404]);

        GeneralUtility::addInstance(WarmupQueueRepository::class, $queueRepository);
        GeneralUtility::addInstance(WarmupHttpClientService::class, $httpClientService);

        self::assertTrue($this->task->execute());
    }

    public function testExecuteReturnsFalseOnException(): void
    {
        $queueRepository = $this->createMock(WarmupQueueRepository::class);
        $queueRepository->method('countOpen')->willThrowException(new \RuntimeException('db down'));

        GeneralUtility::addInstance(WarmupQueueRepository::class, $queueRepository);
        GeneralUtility::addInstance(WarmupHttpClientService::class, $this->createMock(WarmupHttpClientService::class));

        self::assertFalse($this->task->execute());
    }
}
