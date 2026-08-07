<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Event;

final readonly class AfterCriticalUidsUpdatedEvent
{
    public function __construct(
        private int $pageUid,
        private string $bucket,
        private array $previousUids,
        private array $newUids,
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
