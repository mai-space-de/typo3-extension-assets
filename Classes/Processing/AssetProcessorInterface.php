<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Processing;

interface AssetProcessorInterface
{
    public function canProcess(string $filePath): bool;

    public function process(string $content, string $sourcePath): string;
}
