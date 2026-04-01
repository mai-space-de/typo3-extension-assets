<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Collector;

use TYPO3\CMS\Core\SingletonInterface;

abstract class AbstractAssetCollector implements AssetCollectorInterface, SingletonInterface
{
    /** @var array<string, string> Map of identifier => filePath */
    private array $assets = [];

    public function register(string $identifier, string $filePath): void
    {
        // Deduplication: first registration wins
        if (!isset($this->assets[$identifier])) {
            $this->assets[$identifier] = $filePath;
        }
    }

    public function has(string $identifier): bool
    {
        return isset($this->assets[$identifier]);
    }

    public function getAll(): array
    {
        return $this->assets;
    }

    public function reset(): void
    {
        $this->assets = [];
    }

    /**
     * Build the final output string from all registered assets.
     */
    abstract public function build(): string;
}
