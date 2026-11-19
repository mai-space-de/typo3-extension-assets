<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Utility;

use TYPO3\CMS\Core\Core\Environment;

final readonly class AssetPathUtility
{
    public static function isCssFile(string $fileName): bool
    {
        return preg_match('/\.(css|less|sass|scss|styl|stylus|pcss|postcss)$/i', $fileName) === 1;
    }

    public static function isJsFile(string $fileName): bool
    {
        return preg_match('/\.(js|mjs|ts|tsx|jsx)$/i', $fileName) === 1;
    }

    public static function isImageFile(string $fileName): bool
    {
        return preg_match('/\.(avif|webp|jpg|jpeg|png|gif|svg)$/i', $fileName) === 1;
    }

    public static function isFontFile(string $fileName): bool
    {
        return preg_match('/\.(woff2?|ttf|otf|eot)$/i', $fileName) === 1;
    }

    /**
     * Whether the web server can deliver the file: inside the public directory, or a
     * Resources/Public file TYPO3 publishes under _assets/ (Composer mode).
     */
    public static function isPubliclyReachable(string $absolutePath): bool
    {
        $publicPath = rtrim(Environment::getPublicPath(), '/') . '/';
        if (str_starts_with($absolutePath, $publicPath)) {
            return true;
        }

        return Environment::isComposerMode()
            && str_starts_with($absolutePath, rtrim(Environment::getProjectPath(), '/') . '/')
            && str_contains($absolutePath, '/Resources/Public/');
    }
}
