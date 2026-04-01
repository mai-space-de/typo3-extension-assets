<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Traits;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;

/**
 * Provides critical-element awareness to classes that inject AboveFoldCacheService.
 * The consuming class must have $aboveFoldCacheService property of type AboveFoldCacheService.
 */
trait CriticalAwareTrait
{
    protected function isCritical(int $pageUid, int $elementUid): bool
    {
        $allUids = $this->aboveFoldCacheService->getAllCriticalUids($pageUid);
        return in_array($elementUid, $allUids, true);
    }

    protected function getCriticalUidsForPage(int $pageUid): array
    {
        return $this->aboveFoldCacheService->getAllCriticalUids($pageUid);
    }
}
