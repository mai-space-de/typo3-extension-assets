<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use Maispace\MaiAssets\Collector\FontPreloadCollector;
use Maispace\MaiAssets\Traits\FileResolutionTrait;

final class FontPreloadService
{
    use FileResolutionTrait;

    public function __construct(
        private readonly FontPreloadCollector $fontPreloadCollector,
    ) {}

    public function registerCriticalFont(string $path): void
    {
        $resolved = $this->resolveFilePath($path);
        $identifier = md5($resolved);
        $this->fontPreloadCollector->register($identifier, $resolved);
    }
}
