<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;

final class AssetCriticalityResolver
{
    public function __construct(
        private readonly AboveFoldCacheService $aboveFoldCacheService,
        private readonly CriticalDetectionService $criticalDetectionService,
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
}
