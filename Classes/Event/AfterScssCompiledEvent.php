<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Event;

final class AfterScssCompiledEvent
{
    public function __construct(
        private string $compiledCss,
        private readonly string $sourcePath,
    ) {}

    public function getCompiledCss(): string
    {
        return $this->compiledCss;
    }

    public function setCompiledCss(string $compiledCss): void
    {
        $this->compiledCss = $compiledCss;
    }

    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }
}
