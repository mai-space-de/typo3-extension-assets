<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Traits;

use Maispace\MaiAssets\Exception\AssetFileNotFoundException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

trait FileResolutionTrait
{
    protected function resolveFilePath(string $path): string
    {
        if (str_starts_with($path, 'EXT:') || str_starts_with($path, 'PKG:')) {
            $resolved = GeneralUtility::getFileAbsFileName($path);
            if ($resolved === '') {
                $prefix = str_starts_with($path, 'PKG:') ? 'PKG' : 'EXT';
                throw new AssetFileNotFoundException(
                    sprintf('Cannot resolve %s: path: "%s". Package may not be installed.', $prefix, $path),
                    1700000020
                );
            }
            return $resolved;
        }

        if (!PathUtility::isAbsolutePath($path)) {
            $path = Environment::getPublicPath() . '/' . $path;
        }

        return $path;
    }

    protected function resolveFilePathWithSymlinks(string $path): string
    {
        $resolved = $this->resolveFilePath($path);

        $realPath = realpath($resolved);
        if ($realPath === false) {
            throw new AssetFileNotFoundException(
                sprintf('File does not exist or is not accessible: "%s"', $resolved),
                1700000021
            );
        }

        return $realPath;
    }

    protected function fileExists(string $path): bool
    {
        try {
            $resolved = $this->resolveFilePath($path);
            return file_exists($resolved);
        } catch (AssetFileNotFoundException) {
            return false;
        }
    }

    protected function requireFile(string $path): string
    {
        $resolved = $this->resolveFilePath($path);
        if (!file_exists($resolved)) {
            throw new AssetFileNotFoundException(
                sprintf('Required file not found: "%s" (resolved: "%s")', $path, $resolved),
                1700000001
            );
        }
        return $resolved;
    }
}
