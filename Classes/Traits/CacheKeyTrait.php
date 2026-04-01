<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Traits;

trait CacheKeyTrait
{
    protected function buildCacheKey(int $pageUid, string $bucket): string
    {
        return 'page_' . $pageUid . '_' . $bucket;
    }

    protected function buildResetKey(int $pageUid): string
    {
        return 'reset_' . $pageUid;
    }
}
