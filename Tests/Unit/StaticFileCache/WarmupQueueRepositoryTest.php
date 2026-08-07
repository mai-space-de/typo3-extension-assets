<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\StaticFileCache;

use Doctrine\DBAL\Result;
use Maispace\MaiAssets\StaticFileCache\WarmupQueueRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class WarmupQueueRepositoryTest extends TestCase
{
    private const string TABLE = 'tx_maiassets_warmup_queue';

    /** @var ConnectionPool&MockObject */
    private ConnectionPool $connectionPool;

    private WarmupQueueRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->subject = new WarmupQueueRepository($this->connectionPool);
    }

    /**
     * Builds a QueryBuilder double whose fluent methods all return itself,
     * and whose expr() returns an ExpressionBuilder double producing inert
     * predicates. executeQuery()/executeStatement() are stubbed per test.
     *
     * @return QueryBuilder&MockObject
     */
    private function createQueryBuilderMock(): QueryBuilder
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('setFirstResult')->willReturnSelf();
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('delete')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');

        $expr = $this->createMock(ExpressionBuilder::class);
        $expr->method('eq')->willReturn('call_date = 0');
        $expr->method('gt')->willReturn('call_date > 0');
        $expr->method('in')->willReturn('cache_url IN (:dcValue1)');
        $expr->method('and')->willReturn(CompositeExpression::and('1 = 1'));
        $queryBuilder->method('expr')->willReturn($expr);

        return $queryBuilder;
    }

    // ─── addIdentifiers ─────────────────────────────────────────────────────

    public function testAddIdentifiersDoesNothingForEmptyArray(): void
    {
        $this->connectionPool->expects(self::never())->method('getQueryBuilderForTable');
        $this->connectionPool->expects(self::never())->method('getConnectionForTable');

        $this->subject->addIdentifiers([]);
    }

    public function testAddIdentifiersDoesNothingWhenOnlyEmptyStringsGiven(): void
    {
        $this->connectionPool->expects(self::never())->method('getQueryBuilderForTable');
        $this->connectionPool->expects(self::never())->method('getConnectionForTable');

        $this->subject->addIdentifiers(['', '']);
    }

    public function testAddIdentifiersSkipsUrlsAlreadyQueuedAndOpen(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn(['https://example.test/a']);
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with(self::TABLE)
            ->willReturn($queryBuilder);

        $this->connectionPool->expects(self::never())->method('getConnectionForTable');

        $this->subject->addIdentifiers(['https://example.test/a']);
    }

    public function testAddIdentifiersInsertsOnlyNewUrls(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn(['https://example.test/a']);
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with(self::TABLE)
            ->willReturn($queryBuilder);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                self::TABLE,
                self::callback(static function (array $data): bool {
                    self::assertSame('https://example.test/b', $data['cache_url']);
                    self::assertSame(5, $data['cache_priority']);
                    self::assertSame(0, $data['call_date']);
                    self::assertSame('', $data['call_result']);
                    return true;
                }),
            );

        $this->connectionPool
            ->method('getConnectionForTable')
            ->with(self::TABLE)
            ->willReturn($connection);

        $this->subject->addIdentifiers(
            ['https://example.test/a', 'https://example.test/b', ''],
            5,
        );
    }

    // ─── markProcessed ──────────────────────────────────────────────────────

    public function testMarkProcessedDoesNothingForEmptyArray(): void
    {
        $this->connectionPool->expects(self::never())->method('getConnectionForTable');

        $this->subject->markProcessed([]);
    }

    public function testMarkProcessedUpdatesEachResult(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::exactly(2))
            ->method('update')
            ->willReturnCallback(function (string $table, array $data, array $identifier): int {
                self::assertSame(self::TABLE, $table);
                self::assertSame('200', $data['call_result']);
                self::assertContains($identifier['uid'], [1, 2]);
                return 1;
            });

        $this->connectionPool
            ->method('getConnectionForTable')
            ->with(self::TABLE)
            ->willReturn($connection);

        $this->subject->markProcessed([
            ['uid' => 1, 'call_result' => 200],
            ['uid' => 2, 'call_result' => 200],
        ]);
    }

    // ─── countOpen ──────────────────────────────────────────────────────────

    public function testCountOpenReturnsIntFromFetchOne(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn('7');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with(self::TABLE)
            ->willReturn($queryBuilder);

        self::assertSame(7, $this->subject->countOpen());
    }

    // ─── findOpenBatch ──────────────────────────────────────────────────────

    public function testFindOpenBatchReturnsRows(): void
    {
        $rows = [
            ['uid' => 1, 'cache_url' => 'https://example.test/a'],
            ['uid' => 2, 'cache_url' => 'https://example.test/b'],
        ];

        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->expects(self::once())->method('setMaxResults')->with(50)->willReturnSelf();
        $queryBuilder->expects(self::once())->method('setFirstResult')->with(100)->willReturnSelf();

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with(self::TABLE)
            ->willReturn($queryBuilder);

        self::assertSame($rows, $this->subject->findOpenBatch(50, 100));
    }

    // ─── cleanupOld ─────────────────────────────────────────────────────────

    public function testCleanupOldReturnsZeroWhenNothingToDelete(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn([]);
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->expects(self::never())->method('delete');

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with(self::TABLE)
            ->willReturn($queryBuilder);

        self::assertSame(0, $this->subject->cleanupOld());
    }

    public function testCleanupOldDeletesFoundRows(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn([1, 2, 3]);
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->expects(self::once())->method('delete')->with(self::TABLE)->willReturnSelf();
        $queryBuilder->method('executeStatement')->willReturn(3);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with(self::TABLE)
            ->willReturn($queryBuilder);

        self::assertSame(3, $this->subject->cleanupOld());
    }
}
