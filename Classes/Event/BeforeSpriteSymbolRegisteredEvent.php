<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Event;

final class BeforeSpriteSymbolRegisteredEvent
{
    private bool $cancelled = false;

    public function __construct(
        private readonly string $identifier,
        private readonly string $svgPath,
    ) {}

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getSvgPath(): string
    {
        return $this->svgPath;
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
