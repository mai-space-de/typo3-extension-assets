<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Scheduler;

use Maispace\MaiAssets\Scheduler\StaticFileCacheWarmupTask;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class StaticFileCacheWarmupTaskTest extends TestCase
{
    private StaticFileCacheWarmupTask $task;

    protected function setUp(): void
    {
        $this->task = new StaticFileCacheWarmupTask();
        $this->task->setLogger(new NullLogger());
    }

    /**
     * @test
     */
    public function executeReturnsTrue(): void
    {
        $result = $this->task->execute();
        self::assertTrue($result);
    }
}
