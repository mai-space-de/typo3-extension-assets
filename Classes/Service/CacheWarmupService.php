<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use Maispace\MaiAssets\StaticFileCache\WarmupQueueRepository;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Enqueues page URLs into the native warmup queue once a page becomes
 * optimisation-ready (all viewport buckets collected).
 *
 * Called by CacheWarmupListener when AfterCriticalUidsUpdatedEvent fires
 * and the readiness check passes. Uses TYPO3's Site API to resolve page
 * UIDs to fully qualified URLs for every configured language.
 *
 * @see \Maispace\MaiAssets\StaticFileCache\WarmupQueueRepository
 * @see \Maispace\MaiAssets\Scheduler\StaticFileCacheWarmupTask
 */
class CacheWarmupService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly PageOptimizationReadinessService $readinessService,
        private readonly SiteFinder $siteFinder,
        private readonly WarmupQueueRepository $warmupQueueRepository,
    ) {}

    /**
     * If the page is fully ready, resolve all language variants and enqueue
     * them into the native warmup queue.
     *
     * This is idempotent: WarmupQueueRepository::addIdentifiers() skips URLs
     * already present in the queue.
     */
    public function warmupPage(int $pageUid): void
    {
        if ($pageUid <= 0) {
            return;
        }

        if (!$this->readinessService->isReady($pageUid)) {
            return;
        }

        $urls = $this->buildPageUrls($pageUid);

        if ($urls === []) {
            return;
        }

        $this->warmupQueueRepository->addIdentifiers($urls);

        $this->logger?->info(
            sprintf(
                'Enqueued %d URL(s) for page %d into the warmup queue.',
                count($urls),
                $pageUid
            ),
            ['urls' => $urls]
        );
    }

    /**
     * Build fully qualified URLs for every language of the given page.
     *
     * @param int $pageUid
     * @return array<int, string>
     */
    private function buildPageUrls(int $pageUid): array
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageUid);
        } catch (\Throwable $e) {
            $this->logger?->warning(
                sprintf('Could not find site for page %d: %s', $pageUid, $e->getMessage())
            );
            return [];
        }

        $urls = [];

        foreach ($site->getLanguages() as $language) {
            try {
                $uri = $site->getRouter()->generateUri(
                    $pageUid,
                    ['language' => $language]
                );
                $urls[] = (string)$uri;
            } catch (\Throwable $e) {
                $this->logger?->warning(
                    sprintf(
                        'Could not generate URI for page %d, language %d: %s',
                        $pageUid,
                        $language->getLanguageId(),
                        $e->getMessage()
                    )
                );
            }
        }

        return $urls;
    }
}
