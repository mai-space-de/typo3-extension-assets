<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\StaticFileCache;

use Doctrine\DBAL\ArrayParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Persists the static-file-cache warmup queue in tx_maiassets_warmup_queue.
 *
 * Replaces the boost-queue mechanism previously borrowed from the
 * (uninstalled) lochmueller/staticfilecache extension: pages that become
 * optimisation-ready are enqueued here, then crawled by
 * WarmupHttpClientService via the scheduler task so real HTTP requests
 * trigger AfterCacheableContentIsGeneratedEvent and populate the static
 * HTML cache.
 *
 * @see \Maispace\MaiAssets\Service\CacheWarmupService
 * @see \Maispace\MaiAssets\Scheduler\StaticFileCacheWarmupTask
 */
class WarmupQueueRepository
{
    private const string TABLE = 'tx_maiassets_warmup_queue';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Enqueue URLs that are not already queued and open (call_date = 0).
     *
     * @param array<int, string> $urls
     */
    public function addIdentifiers(array $urls, int $priority = 0): void
    {
        $urls = array_values(array_unique(array_filter($urls, static fn (string $url): bool => $url !== '')));
        if ($urls === []) {
            return;
        }

        $existing = $this->findExistingOpenUrls($urls);
        $newUrls = array_diff($urls, $existing);
        if ($newUrls === []) {
            return;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = time();

        foreach ($newUrls as $url) {
            $connection->insert(self::TABLE, [
                'cache_url' => $url,
                'cache_priority' => $priority,
                'page_uid' => 0,
                'queued_date' => $now,
                'call_date' => 0,
                'call_result' => '',
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findOpenBatch(int $limit, int $offset = 0): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('call_date', 0))
            ->orderBy('cache_priority', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Mark queue entries as processed.
     *
     * @param array<int, array{uid: int, call_result: int}> $results
     */
    public function markProcessed(array $results): void
    {
        if ($results === []) {
            return;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = time();

        foreach ($results as $result) {
            $connection->update(
                self::TABLE,
                [
                    'call_date' => $now,
                    'call_result' => (string)$result['call_result'],
                ],
                ['uid' => $result['uid']],
            );
        }
    }

    public function countOpen(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('call_date', 0))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Delete already-processed entries (call_date > 0), oldest first, in batches.
     *
     * @return int Number of deleted rows
     */
    public function cleanupOld(int $limit = 1000): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $uids = $queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->gt('call_date', 0))
            ->orderBy('call_date', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchFirstColumn();

        if ($uids === []) {
            return 0;
        }

        $deleteQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $deleteQueryBuilder
            ->delete(self::TABLE)
            ->where(
                $deleteQueryBuilder->expr()->in(
                    'uid',
                    $deleteQueryBuilder->createNamedParameter($uids, ArrayParameterType::INTEGER),
                ),
            )
            ->executeStatement();
    }

    /**
     * @param array<int, string> $urls
     * @return array<int, string>
     */
    private function findExistingOpenUrls(array $urls): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        $result = $queryBuilder
            ->select('cache_url')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->and(
                    $queryBuilder->expr()->in(
                        'cache_url',
                        $queryBuilder->createNamedParameter($urls, ArrayParameterType::STRING),
                    ),
                    $queryBuilder->expr()->eq('call_date', 0),
                ),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(strval(...), $result);
    }
}
