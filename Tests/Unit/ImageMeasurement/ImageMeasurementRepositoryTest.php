<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\ImageMeasurement;

use Doctrine\DBAL\Result;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementRepository;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementScale;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class ImageMeasurementRepositoryTest extends TestCase
{
    public function testConvergedStepKeepsItsTimestampFreshSoItIsNotPruned(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['css_width' => 640, 'hits' => ImageMeasurementScale::MAX_HITS_PER_STEP],
        ]);

        $updatedFields = [];
        $queryBuilder = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'orderBy', 'update'] as $method) {
            $queryBuilder->method($method)->willReturnSelf();
        }
        $queryBuilder->method('set')->willReturnCallback(function (string $field) use (&$updatedFields, $queryBuilder): QueryBuilder {
            $updatedFields[] = $field;

            return $queryBuilder;
        });
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');
        $queryBuilder->method('expr')->willReturn($this->createMock(ExpressionBuilder::class));
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->expects(self::once())->method('executeStatement')->willReturn(1);

        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($queryBuilder);
        $connection->expects(self::never())->method('insert');
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $subject = new ImageMeasurementRepository($connectionPool);

        self::assertFalse($subject->record('e1.r2', 1440, 640));
        // Only the timestamp is touched, no sample is counted.
        self::assertSame(['tstamp'], $updatedFields);
    }
}
