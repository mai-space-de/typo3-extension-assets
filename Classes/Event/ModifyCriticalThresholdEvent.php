<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Event;

final class ModifyCriticalThresholdEvent
{
    public function __construct(
        private int $threshold,
        private readonly int $colPos,
        private readonly int $pageUid,
    ) {}

    public function getThreshold(): int
    {
        return $this->threshold;
    }

    public function setThreshold(int $threshold): void
    {
        $this->threshold = $threshold;
    }

    public function getColPos(): int
    {
        return $this->colPos;
    }

    public function getPageUid(): int
    {
        return $this->pageUid;
    }
}
