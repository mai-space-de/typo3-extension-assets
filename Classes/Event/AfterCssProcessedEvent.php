<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Event;

final class AfterCssProcessedEvent
{
    public function __construct(
        private string $processedCss,
        private readonly string $sourcePath,
    ) {}

    public function getProcessedCss(): string
    {
        return $this->processedCss;
    }

    public function setProcessedCss(string $processedCss): void
    {
        $this->processedCss = $processedCss;
    }

    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }
}
