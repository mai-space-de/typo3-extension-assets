<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\StaticFileCache;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Removes static HTML cache files from the filesystem when page content
 * changes or bucket data is cleared.
 *
 * Uses the same URL-to-filesystem mapping as StaticFileCacheDirectory.
 * On bucket reset (clearCriticalUids / bumpResetTimestamp), all language
 * variants of a page are purged from the static cache directory so that
 * the next request serves dynamic content.
 *
 * @see StaticFileCacheDirectory
 * @see \Maispace\MaiAssets\Cache\AboveFoldCacheService
 */
class StaticFileRemovalService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly StaticFileCacheDirectory $cacheDirectory,
    ) {}

    /**
     * Purge static cache files for the given page across all configured
     * languages of the site that owns this page.
     *
     * This is the main entry point called from bucket-reset methods.
     * It resolves the site via SiteFinder, then iterates every language.
     *
     * @param int $pageUid Page UID
     * @param Site|null $site Pre-resolved site (for testing; resolves automatically when null)
     */
    public function purgeByPageUid(int $pageUid, ?Site $site = null): void
    {
        if ($pageUid <= 0) {
            return;
        }

        try {
            $site ??= $this->resolveSiteByPageId($pageUid);
        } catch (\Throwable $e) {
            $this->logger?->warning(
                sprintf('Could not find site for page %d: %s', $pageUid, $e->getMessage())
            );
            return;
        }

        foreach ($site->getLanguages() as $language) {
            $this->purgeForLanguage($pageUid, $language->getLanguageId(), $site);
        }
    }

    /**
     * Purge static cache files for the given page in exactly one language.
     * Allows callers that already know the target language to skip iteration.
     */
    public function purgeForLanguage(int $pageUid, int $languageUid, ?Site $site = null): void
    {
        if ($pageUid <= 0) {
            return;
        }

        try {
            $site ??= $this->resolveSiteByPageId($pageUid);
        } catch (\Throwable $e) {
            $this->logger?->warning(
                sprintf('Could not find site for page %d: %s', $pageUid, $e->getMessage())
            );
            return;
        }

        $targetLanguage = null;
        foreach ($site->getLanguages() as $language) {
            if ($language->getLanguageId() === $languageUid) {
                $targetLanguage = $language;
                break;
            }
        }

        if ($targetLanguage === null) {
            return;
        }

        try {
            $uri = $site->getRouter()->generateUri(
                $pageUid,
                ['language' => $targetLanguage]
            );
        } catch (\Throwable $e) {
            $this->logger?->warning(
                sprintf(
                    'Could not generate URI for page %d, language %d: %s',
                    $pageUid,
                    $languageUid,
                    $e->getMessage()
                )
            );
            return;
        }

        $fullUrl = $this->buildAbsoluteUrlFromSite($site, (string)$uri);

        if (!$this->cacheDirectory->isValidUri($fullUrl)) {
            return;
        }

        try {
            $pageDir = $this->cacheDirectory->getPageDirectory($fullUrl);
            if (is_dir($pageDir)) {
                GeneralUtility::rmdir($pageDir, true);
            }
        } catch (\Throwable $e) {
            $this->logger?->warning(
                sprintf(
                    'Could not remove static cache directory for page %d, language %d: %s',
                    $pageUid,
                    $languageUid,
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Resolve a Site by page UID using the SiteFinder.
     *
     * @throws \TYPO3\CMS\Core\Exception\SiteNotFoundException
     */
    private function resolveSiteByPageId(int $pageUid): Site
    {
        return GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($pageUid);
    }

    private function buildAbsoluteUrlFromSite(Site $site, string $pagePath): string
    {
        $siteBase = rtrim((string)$site->getBase(), '/');
        return $siteBase . '/' . ltrim($pagePath, '/');
    }
}
