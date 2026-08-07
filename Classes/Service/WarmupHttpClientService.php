<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;

/**
 * Concurrent batch HTTP requester for the static-file-cache warmup crawler.
 *
 * Replaces the boost-crawling half of the (uninstalled) lochmueller/staticfilecache
 * extension's ClientService: issues real GET requests against page URLs so that
 * AfterCacheableContentIsGeneratedEvent fires and StaticHtmlWriterListener
 * populates the static HTML cache.
 *
 * @see \Maispace\MaiAssets\StaticFileCache\WarmupQueueRepository
 * @see \Maispace\MaiAssets\Scheduler\StaticFileCacheWarmupTask
 */
class WarmupHttpClientService
{
    public function __construct(
        private readonly GuzzleClientFactory $guzzleClientFactory,
    ) {}

    /**
     * @param array<int|string, string> $urls
     * @return array<int|string, int> HTTP status codes (0 on request failure), indexed like $urls
     */
    public function runBatch(array $urls, int $concurrency = 10): array
    {
        if ($urls === []) {
            return [];
        }

        $client = $this->guzzleClientFactory->getClient();
        $results = [];

        $requests = static function () use ($urls): \Generator {
            foreach ($urls as $key => $url) {
                yield $key => new Request('GET', $url);
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => $concurrency,
            // Treat 4xx/5xx as fulfilled responses so callers get the real
            // status code instead of every non-2xx collapsing into "rejected".
            'options' => ['http_errors' => false],
            'fulfilled' => static function (ResponseInterface $response, int|string $key) use (&$results): void {
                $results[$key] = $response->getStatusCode();
            },
            'rejected' => static function (\Throwable $reason, int|string $key) use (&$results): void {
                $results[$key] = 0;
            },
        ]);

        $pool->promise()->wait();

        return $results;
    }
}
