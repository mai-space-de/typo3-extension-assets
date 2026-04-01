<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Event;

final class BeforeObserverScriptInjectedEvent
{
    public function __construct(
        private string $script,
        private bool $cancelled = false,
    ) {}

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function getScript(): string
    {
        return $this->script;
    }

    public function setScript(string $script): void
    {
        $this->script = $script;
    }
}
