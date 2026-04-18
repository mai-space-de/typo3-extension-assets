<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Event;

final class AfterJsProcessedEvent
{
    public function __construct(
        private string $processedJs,
        private readonly string $sourcePath,
    ) {}

    public function getProcessedJs(): string
    {
        return $this->processedJs;
    }

    public function setProcessedJs(string $processedJs): void
    {
        $this->processedJs = $processedJs;
    }

    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }
}
