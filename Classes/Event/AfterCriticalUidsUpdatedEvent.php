<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Event;

final class AfterCriticalUidsUpdatedEvent
{
    public function __construct(
        private readonly int $pageUid,
        private readonly string $bucket,
        private readonly array $previousUids,
        private readonly array $newUids,
    ) {}

    public function getPageUid(): int
    {
        return $this->pageUid;
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    public function getPreviousUids(): array
    {
        return $this->previousUids;
    }

    public function getNewUids(): array
    {
        return $this->newUids;
    }
}
