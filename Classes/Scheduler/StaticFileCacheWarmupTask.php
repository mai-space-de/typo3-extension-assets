<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Scheduler;

use Maispace\MaiAssets\Service\WarmupHttpClientService;
use Maispace\MaiAssets\StaticFileCache\WarmupQueueRepository;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Scheduler task that processes the native warmup queue.
 *
 * This task should be scheduled to run every 5 minutes. It processes URLs
 * that have been added to the queue by CacheWarmupService when pages reach
 * readiness, crawling them via WarmupHttpClientService so real HTTP
 * requests trigger AfterCacheableContentIsGeneratedEvent and populate the
 * static HTML cache.
 *
 * The class name is kept stable even though it no longer touches
 * staticfilecache, since existing scheduled task records reference it by
 * FQCN.
 *
 * @see \Maispace\MaiAssets\StaticFileCache\WarmupQueueRepository
 * @see \Maispace\MaiAssets\Service\WarmupHttpClientService
 */
final class StaticFileCacheWarmupTask extends AbstractTask implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @return bool
     */
    public function execute(): bool
    {
        $queueRepository = GeneralUtility::makeInstance(WarmupQueueRepository::class);
        $httpClientService = GeneralUtility::makeInstance(WarmupHttpClientService::class);

        try {
            $queueCount = $queueRepository->countOpen();
            if ($queueCount === 0) {
                $this->logger?->debug('No items in warmup queue');
                return true;
            }

            $startTime = time();
            $totalProcessed = 0;
            $successCount = 0;
            $failedCount = 0;
            $stopProcessingAfter = 240;
            $batchSize = 50;
            $concurrency = 10;
            $offset = 0;

            while ($totalProcessed < 500) {
                if (time() >= $startTime + $stopProcessingAfter) {
                    $this->logger?->info(
                        sprintf(
                            'Stopped warmup queue processing after %d seconds',
                            $stopProcessingAfter
                        )
                    );
                    break;
                }

                $entriesBatch = $queueRepository->findOpenBatch($batchSize, $offset);

                if (empty($entriesBatch)) {
                    break;
                }

                $urlsByUid = [];
                foreach ($entriesBatch as $entry) {
                    $urlsByUid[(int)$entry['uid']] = (string)$entry['cache_url'];
                }

                $statusResults = $httpClientService->runBatch($urlsByUid, $concurrency);

                $batchSuccess = count(array_filter($statusResults, static fn(int $status): bool => $status === 200));
                $successCount += $batchSuccess;
                $failedCount += (count($entriesBatch) - $batchSuccess);

                $totalProcessed += count($entriesBatch);

                $markResults = [];
                foreach ($statusResults as $uid => $status) {
                    $markResults[] = ['uid' => $uid, 'call_result' => $status];
                }
                $queueRepository->markProcessed($markResults);

                if (count($entriesBatch) < $batchSize) {
                    break;
                }

                $offset += $batchSize;
            }

            $duration = time() - $startTime;

            $this->logger?->info(
                sprintf(
                    'Processed %d warmup queue items in %d seconds (success: %d, failed: %d)',
                    $totalProcessed,
                    $duration,
                    $successCount,
                    $failedCount
                )
            );

            $this->cleanupQueue($queueRepository);

            return true;
        } catch (\Throwable $e) {
            $this->logger?->error(
                sprintf(
                    'Error processing warmup queue: %s',
                    $e->getMessage()
                )
            );
            return false;
        }
    }

    private function cleanupQueue(WarmupQueueRepository $queueRepository): void
    {
        try {
            $batchSize = 1000;
            $totalDeleted = 0;

            while (true) {
                $deleted = $queueRepository->cleanupOld($batchSize);
                $totalDeleted += $deleted;

                if ($deleted < $batchSize) {
                    break;
                }
            }

            if ($totalDeleted > 0) {
                $this->logger?->info(
                    sprintf('Cleaned up %d old queue entries', $totalDeleted)
                );
            }
        } catch (\Throwable $e) {
            $this->logger?->warning(
                sprintf('Error cleaning up queue entries: %s', $e->getMessage())
            );
        }
    }
}
