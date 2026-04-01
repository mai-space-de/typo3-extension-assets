<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Processing;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;

final class CompressionProcessor
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function compressFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException(
                sprintf('File not found for compression: "%s"', $filePath),
                1700000003
            );
        }

        $content = (string)file_get_contents($filePath);
        $level = $this->extensionConfiguration->getCompressionLevel();

        // Write gzip variant
        $gzContent = gzencode($content, $level);
        if ($gzContent !== false) {
            file_put_contents($filePath . '.gz', $gzContent);
        }

        // Write brotli variant if available and enabled
        if ($this->extensionConfiguration->isEnableBrotli() && function_exists('brotli_compress')) {
            $brContent = brotli_compress($content, $level);
            if ($brContent !== false) {
                file_put_contents($filePath . '.br', $brContent);
            }
        }
    }
}
