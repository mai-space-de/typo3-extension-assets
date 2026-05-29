<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Scheduler;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Scheduler task that processes the staticfilecache boost queue.
 *
 * This task should be scheduled to run every 5 minutes. It processes
 * URLs that have been added to the queue by CacheWarmupService when
 * pages reach readiness. If the staticfilecache extension is not installed,
 * the task completes silently.
 *
 * The actual queue processing is handled by staticfilecache's QueueService.
 */
final class StaticFileCacheWarmupTask extends AbstractTask implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @return bool
     */
    public function execute(): bool
    {
        if (!class_exists(\SFC\Staticfilecache\Service\QueueService::class)) {
            $this->logger?->info(
                'staticfilecache extension not installed, skipping queue processing'
            );
            return true;
        }

        if (!class_exists(\SFC\Staticfilecache\Domain\Repository\QueueRepository::class)) {
            return false;
        }

        try {
            /** @var \SFC\Staticfilecache\Domain\Repository\QueueRepository $queueRepository */
            $queueRepository = GeneralUtility::makeInstance(
                \SFC\Staticfilecache\Domain\Repository\QueueRepository::class
            );

            $queueCount = $queueRepository->countOpen();
            if ($queueCount === 0) {
                $this->logger?->debug('No items in staticfilecache queue');
                return true;
            }

            /** @var \SFC\Staticfilecache\Service\QueueService $queueService */
            $queueService = GeneralUtility::makeInstance(
                \SFC\Staticfilecache\Service\QueueService::class
            );

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
                            'Stopped staticfilecache queue processing after %d seconds',
                            $stopProcessingAfter
                        )
                    );
                    break;
                }

                $entriesBatch = $queueRepository->findOpenBatch($batchSize, $offset);

                if (empty($entriesBatch)) {
                    break;
                }

                $statusResults = $queueService->runBatchRequests($entriesBatch, $concurrency);

                $batchSuccess = count(array_filter($statusResults, fn($status) => $status === 200));
                $successCount += $batchSuccess;
                $failedCount += (count($entriesBatch) - $batchSuccess);

                $totalProcessed += count($entriesBatch);

                if (count($entriesBatch) < $batchSize) {
                    break;
                }

                $offset += $batchSize;
            }

            $duration = time() - $startTime;

            $this->logger?->info(
                sprintf(
                    'Processed %d staticfilecache queue items in %d seconds (success: %d, failed: %d)',
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
                    'Error processing staticfilecache queue: %s',
                    $e->getMessage()
                )
            );
            return false;
        }
    }

    /**
     * Clean up old queue entries.
     *
     * @param \SFC\Staticfilecache\Domain\Repository\QueueRepository $queueRepository
     * @return void
     */
    private function cleanupQueue(
        \SFC\Staticfilecache\Domain\Repository\QueueRepository $queueRepository
    ): void {
        try {
            $batchSize = 1000;
            $totalDeleted = 0;

            while (true) {
                $batch = $queueRepository->findOldBatch($batchSize);
                if (empty($batch)) {
                    break;
                }

                $uids = array_column($batch, 'uid');
                $count = $queueRepository->bulkDelete($uids);
                $totalDeleted += $count;

                if (count($batch) < $batchSize) {
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
