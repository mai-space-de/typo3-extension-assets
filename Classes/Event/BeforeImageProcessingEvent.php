<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Event;

final class BeforeImageProcessingEvent
{
    private bool $cancelled = false;

    public function __construct(
        private readonly object $fileReference,
        private array $breakpoints,
    ) {}

    public function getFileReference(): object
    {
        return $this->fileReference;
    }

    public function getBreakpoints(): array
    {
        return $this->breakpoints;
    }

    public function setBreakpoints(array $breakpoints): void
    {
        $this->breakpoints = $breakpoints;
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
