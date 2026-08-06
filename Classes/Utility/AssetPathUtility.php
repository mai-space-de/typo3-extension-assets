<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Utility;

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
}
