<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Traits;

use TYPO3\CMS\Core\Utility\GeneralUtility;

trait FileResolutionTrait
{
    protected function resolveFilePath(string $path): string
    {
        if (str_starts_with($path, 'EXT:')) {
            return GeneralUtility::getFileAbsFileName($path);
        }
        return $path;
    }

    protected function fileExists(string $path): bool
    {
        $resolved = $this->resolveFilePath($path);
        return $resolved !== '' && file_exists($resolved);
    }

    protected function requireFile(string $path): string
    {
        $resolved = $this->resolveFilePath($path);
        if ($resolved === '' || !file_exists($resolved)) {
            throw new \RuntimeException(
                sprintf('Required file not found: "%s" (resolved: "%s")', $path, $resolved),
                1700000001
            );
        }
        return $resolved;
    }
}
