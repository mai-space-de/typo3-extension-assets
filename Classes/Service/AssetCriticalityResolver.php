<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;

final readonly class AssetCriticalityResolver
{
    public function __construct(
        private AboveFoldCacheService $aboveFoldCacheService,
        private CriticalDetectionService $criticalDetectionService,
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Page-level: any observer data exists for this PID across any bucket.
     * Used by CSS and JS ViewHelpers.
     */
    public function pageHasObserverData(int $pageUid): bool
    {
        return $this->aboveFoldCacheService->getAllCriticalUids($pageUid) !== [];
    }

    /**
     * Element-level: this content element UID is in the above-fold set.
     * Used by image ViewHelpers.
     */
    public function isElementAboveFold(int $elementUid, int $pageUid): bool
    {
        return $this->criticalDetectionService->isCritical($pageUid, $elementUid);
    }

    /**
     * Page-level: observer data exists for EVERY configured viewport bucket
     * AND at least one bucket has non-empty critical UIDs. More conservative
     * than pageHasObserverData(), which returns true as soon as ANY single
     * bucket has data.
     *
     * Use this when you want to wait until all viewport sizes
     * (mobile, tablet, desktop) have reported before trusting the
     * auto-criticality decision.
     */
    public function pageHasCompleteObserverData(int $pageUid): bool
    {
        $storedBuckets = $this->aboveFoldCacheService->getBucketNames($pageUid);
        if ($storedBuckets === []) {
            return false;
        }

        $configuredBuckets = array_keys($this->extensionConfiguration->getViewportBuckets());

        // All configured buckets must have been reported
        $missing = array_diff($configuredBuckets, $storedBuckets);
        if ($missing !== []) {
            return false;
        }

        // At least one bucket must have non-empty critical UIDs
        return $this->aboveFoldCacheService->getAllCriticalUids($pageUid) !== [];
    }
}
