<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Collector;

interface AssetCollectorInterface
{
    public function register(string $identifier, string $filePath): void;

    public function has(string $identifier): bool;

    public function getAll(): array;

    public function reset(): void;
}
