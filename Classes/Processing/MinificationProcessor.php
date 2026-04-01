<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Processing;

use MatthiasMullie\Minify\CSS;
use MatthiasMullie\Minify\JS;

final class MinificationProcessor extends AbstractAssetProcessor
{
    public function canProcess(string $filePath): bool
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($ext, ['css', 'js'], true);
    }

    protected function doProcess(string $content, string $sourcePath): string
    {
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        if ($ext === 'js') {
            $minifier = new JS();
            $minifier->add($content);
            return $minifier->minify();
        }

        $minifier = new CSS();
        $minifier->add($content);
        return $minifier->minify();
    }

    protected function getSettingsHash(): array
    {
        return ['type' => 'minify'];
    }

    protected function getCacheExtension(): string
    {
        return 'css';
    }

    protected function getContentType(string $sourcePath): string
    {
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        return $ext === 'js' ? 'js' : 'css';
    }
}
