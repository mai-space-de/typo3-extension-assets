<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Hook;

use Maispace\MaiAssets\Hook\StaticFileCacheFlushHook;
use Maispace\MaiAssets\StaticFileCache\StaticFileIndex;
use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class StaticFileCacheFlushHookTest extends TestCase
{
    /** @var StaticFileRemovalService&MockObject */
    private StaticFileRemovalService $removal;
    /** @var StaticFileIndex&MockObject */
    private StaticFileIndex $index;
    /** @var list<int> */
    private array $purgedPages = [];

    protected function setUp(): void
    {
        $this->removal = $this->createMock(StaticFileRemovalService::class);
        $this->removal->method('purgeByPageUid')->willReturnCallback(function (int $pageUid): void {
            $this->purgedPages[] = $pageUid;
        });
        $this->index = $this->createMock(StaticFileIndex::class);
    }

    public function testRecordChangePurgesThePagesOfItsTags(): void
    {
        // DataHandler passes tags as keys for record changes.
        $this->index->method('findByTags')->with(['pageId_7', 'tt_content_42', 'tx_news_pid_5'])
            ->willReturn([['page' => 12, 'language' => 0], ['page' => 12, 'language' => 1]]);

        $this->hook()->clearCachePostProc([
            'table' => 'tt_content',
            'uid' => 42,
            'uid_page' => 7,
            'tags' => ['pageId_7' => true, 'tt_content_42' => true, 'tx_news_pid_5' => true],
        ]);

        self::assertSame([7, 12], $this->purgedPages);
    }

    public function testClearCacheCommandForOnePage(): void
    {
        $this->index->method('findByTags')->willReturn([]);

        // DataHandler passes tags as a list for clear cache commands.
        $this->hook()->clearCachePostProc(['cacheCmd' => '9', 'tags' => ['pageId_9']]);

        self::assertSame([9], $this->purgedPages);
    }

    public function testFlushingAllOrFrontendCachesRemovesEveryFile(): void
    {
        $this->removal->expects(self::exactly(2))->method('purgeAll');

        $this->hook()->clearCachePostProc(['cacheCmd' => 'pages', 'tags' => []]);
        $this->hook()->clearCachePostProc(['cacheCmd' => 'ALL', 'tags' => []]);
    }

    public function testErrorsNeverBreakSaving(): void
    {
        $this->index->method('findByTags')->willThrowException(new \RuntimeException('db down', 1763700001));

        $this->hook()->clearCachePostProc(['tags' => ['pageId_1' => true]]);

        self::assertSame([], $this->purgedPages);
    }

    private function hook(): StaticFileCacheFlushHook
    {
        return new StaticFileCacheFlushHook($this->removal, $this->index);
    }
}
