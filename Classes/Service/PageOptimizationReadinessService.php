<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;

/**
 * Determines whether a page has accumulated observer data for all configured
 * viewport buckets — the readiness criterion for full optimisation delivery.
 *
 * A page is "optimisation-ready" only after real (or synthetic) user visits
 * from each viewport bucket have reported above-fold element UIDs. Until then
 * the page may serve non-optimised assets (no inlining, no priority hints) on
 * uncached requests, which is the intended warm-up model.
 */
final class PageOptimizationReadinessService
{
    public function __construct(
        private readonly AboveFoldCacheService $aboveFoldCacheService,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Whether all configured viewport buckets have observer data for this page.
     *
     * @param int $pageUid Page ID to check.
     * @return bool True when all configured viewport bucket names are present
     *              in the stored bucket index for this page.
     */
    public function isReady(int $pageUid): bool
    {
        if ($pageUid <= 0) {
            return false;
        }

        $configuredBuckets = array_keys($this->extensionConfiguration->getViewportBuckets());

        if ($configuredBuckets === []) {
            return false;
        }

        $storedBuckets = $this->aboveFoldCacheService->getBucketNames($pageUid);

        return !array_diff($configuredBuckets, $storedBuckets);
    }
}
