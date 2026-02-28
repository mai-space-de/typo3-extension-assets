<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Service;

use Maispace\MaispaceAssets\Cache\AssetCacheManager;
use Maispace\MaispaceAssets\Event\AfterCriticalCssExtractedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for extracting, caching, and retrieving per-page critical CSS and JS.
 *
 * "Critical" in this context means CSS rules and JS scripts that affect elements
 * visible above the fold (within the initial viewport) at a given viewport size.
 *
 * Workflow
 * ========
 *  1. The CLI command `maispace:assets:critical:extract` calls extractForPage() for
 *     every page URL at each configured viewport (mobile + desktop by default).
 *  2. extractForPage() spawns Chromium via ChromiumCdpClient, navigates to the URL,
 *     uses the CSS Coverage API to find used rules, and JS evaluation to identify
 *     elements above the fold — then stores the result in the TYPO3 cache.
 *  3. CriticalCssInlineMiddleware calls getCriticalCss() / getCriticalJs() on every
 *     page request and injects the cached strings as inline <style>/<script> blocks.
 *
 * Cache keys include the page UID and viewport name, so each page/viewport pair is
 * stored independently. Cache entries are grouped under the tags 'maispace_critical'
 * and 'maispace_critical_p{pageUid}' for selective purging.
 *
 * @see ChromiumCdpClient
 * @see \Maispace\MaispaceAssets\Command\CriticalCssExtractCommand
 * @see \Maispace\MaispaceAssets\Middleware\CriticalCssInlineMiddleware
 */
class CriticalAssetService
{
    public function __construct(
        private readonly AssetCacheManager $cache,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ─── Extraction ───────────────────────────────────────────────────────────

    /**
     * Extract and cache critical CSS and JS for a single page at every given viewport.
     *
     * The method spawns one Chromium instance per call and navigates to the same URL
     * once per viewport. Each extraction result is dispatched through
     * AfterCriticalCssExtractedEvent before being written to cache, giving listeners
     * a chance to post-process or replace the content.
     *
     * @param int                                           $pageUid     TYPO3 page UID
     * @param string                                        $pageUrl     Absolute frontend URL
     * @param string                                        $chromiumBin Absolute path to chromium/chrome binary
     * @param array<string, array{width: int, height: int}> $viewports   Keyed by viewport name, e.g.
     *                                                                   ['mobile'=>['width'=>375,'height'=>667]]
     * @param int                                           $languageId  TYPO3 language ID
     * @param int                                           $workspaceId TYPO3 workspace ID
     * @param int                                           $connectMs   Chromium start timeout (ms)
     * @param int                                           $loadMs      Per-page load timeout (ms)
     *
     * @throws \Throwable Propagated from ChromiumCdpClient if Chromium fails to start or navigate
     */
    public function extractForPage(
        int $pageUid,
        string $pageUrl,
        string $chromiumBin,
        array $viewports,
        int $languageId = 0,
        int $workspaceId = 0,
        int $connectMs = 5000,
        int $loadMs = 15000,
    ): void {
        $client = new ChromiumCdpClient($chromiumBin, $connectMs, $loadMs);

        try {
            $client->start();

            foreach ($viewports as $viewportName => $size) {
                $width = (int)$size['width'];
                $height = (int)$size['height'];

                $this->logger->debug('maispace_assets: extracting critical assets', [
                    'pageUid'     => $pageUid,
                    'languageId'  => $languageId,
                    'workspaceId' => $workspaceId,
                    'viewport'    => $viewportName,
                    'url'         => $pageUrl,
                    'width'       => $width,
                    'height'      => $height,
                ]);

                $client->setViewport($width, $height);
                $client->navigate($pageUrl);

                $css = $client->getAboveFoldCriticalCss($height);
                $js = $client->getAboveFoldCriticalJs($height);

                /** @var AfterCriticalCssExtractedEvent $event */
                $event = $this->dispatcher->dispatch(
                    new AfterCriticalCssExtractedEvent($pageUid, $viewportName, $languageId, $workspaceId, $css, $js),
                );

                $css = $event->getCriticalCss();
                $js = $event->getCriticalJs();

                $tags = $this->cache->buildPageTags($pageUid, $languageId, $workspaceId);

                if ($css !== '') {
                    $this->cache->set($this->cache->buildCriticalCssKey($pageUid, $viewportName, $languageId, $workspaceId), $css, $tags);
                    $this->logger->info('maispace_assets: cached critical CSS', [
                        'pageUid'     => $pageUid,
                        'languageId'  => $languageId,
                        'workspaceId' => $workspaceId,
                        'viewport'    => $viewportName,
                        'bytes'       => strlen($css),
                    ]);
                }

                if ($js !== '') {
                    $this->cache->set($this->cache->buildCriticalJsKey($pageUid, $viewportName, $languageId, $workspaceId), $js, $tags);
                    $this->logger->info('maispace_assets: cached critical JS', [
                        'pageUid'     => $pageUid,
                        'languageId'  => $languageId,
                        'workspaceId' => $workspaceId,
                        'viewport'    => $viewportName,
                        'bytes'       => strlen($js),
                    ]);
                }
            }
        } finally {
            $client->close();
        }
    }

    // ─── Retrieval ────────────────────────────────────────────────────────────

    /**
     * Return cached critical CSS for the given page UID, viewport name, language, and workspace.
     * Returns null when no entry has been extracted yet (cold cache).
     */
    public function getCriticalCss(int $pageUid, string $viewport, int $languageId = 0, int $workspaceId = 0): ?string
    {
        $key = $this->cache->buildCriticalCssKey($pageUid, $viewport, $languageId, $workspaceId);
        $value = $this->cache->has($key) ? $this->cache->get($key) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Return cached critical JS for the given page UID, viewport name, language, and workspace.
     * Returns null when no entry has been extracted yet (cold cache).
     */
    public function getCriticalJs(int $pageUid, string $viewport, int $languageId = 0, int $workspaceId = 0): ?string
    {
        $key = $this->cache->buildCriticalJsKey($pageUid, $viewport, $languageId, $workspaceId);
        $value = $this->cache->has($key) ? $this->cache->get($key) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    // ─── Cache management ─────────────────────────────────────────────────────

    /**
     * Purge all cached critical CSS and JS for a specific page UID, and optionally language/workspace.
     * Useful after a page's content or template changes.
     */
    public function purgePage(int $pageUid, ?int $languageId = null, ?int $workspaceId = null): void
    {
        $tag = 'maispace_critical_p' . $pageUid;
        if ($languageId !== null) {
            $tag .= '_l' . $languageId;
        }
        if ($workspaceId !== null) {
            $tag .= '_w' . $workspaceId;
        }
        $this->cache->flushByTag($tag);
    }

    /**
     * Purge all critical CSS and JS across every page.
     */
    public function purgeAll(): void
    {
        $this->cache->flushByTag('maispace_critical');
    }
}
