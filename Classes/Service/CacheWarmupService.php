<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Enqueues page URLs into the staticfilecache boost queue once a page
 * becomes optimisation-ready (all viewport buckets collected).
 *
 * Called by CacheWarmupListener when AfterCriticalUidsUpdatedEvent fires
 * and the readiness check passes. Uses TYPO3's Site API to resolve page
 * UIDs to fully qualified URLs for every configured language.
 *
 * The staticfilecache dependency (QueueService) is optional — when the
 * extension is not installed, warmup is silently skipped.
 */
class CacheWarmupService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly PageOptimizationReadinessService $readinessService,
        private readonly SiteFinder $siteFinder,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * If the page is fully ready, resolve all language variants and enqueue
     * them into the staticfilecache boost queue.
     *
     * This is idempotent: QueueService->addIdentifiers() skips URLs already
     * present in the queue. When staticfilecache is not installed, warmup
     * is silently skipped.
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

        $this->enqueueUrls($urls);

        $this->logger?->info(
            sprintf(
                'Enqueued %d URL(s) for page %d into staticfilecache boost queue.',
                count($urls),
                $pageUid
            ),
            ['urls' => $urls]
        );
    }

    /**
     * Enqueue URLs into the staticfilecache boost queue when available.
     *
     * Uses runtime class-existence check to avoid a compile-time dependency
     * on the optional staticfilecache extension.
     */
    private function enqueueUrls(array $urls): void
    {
        if (!class_exists(\SFC\Staticfilecache\Service\QueueService::class)) {
            return;
        }

        /** @var \SFC\Staticfilecache\Service\QueueService $queueService */
        $queueService = GeneralUtility::makeInstance(\SFC\Staticfilecache\Service\QueueService::class);
        $queueService->addIdentifiers($urls, \SFC\Staticfilecache\Service\QueueService::PRIORITY_LOW);
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

        if (!$site instanceof SiteInterface) {
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
