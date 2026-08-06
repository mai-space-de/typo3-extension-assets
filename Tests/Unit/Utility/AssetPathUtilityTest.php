<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Utility;

use Maispace\MaiAssets\Utility\AssetPathUtility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AssetPathUtilityTest extends TestCase
{
    #[Test]
    #[DataProvider('cssFileProvider')]
    public function isCssFileReturnsCorrectResult(string $fileName, bool $expected): void
    {
        $result = AssetPathUtility::isCssFile($fileName);
        self::assertSame($expected, $result);
    }

    public static function cssFileProvider(): array
    {
        return [
            'plain css' => ['style.css', true],
            'uppercase CSS' => ['style.CSS', true],
            'scss file' => ['style.scss', true],
            'sass file' => ['style.sass', true],
            'less file' => ['style.less', true],
            'stylus file' => ['style.styl', true],
            'stylus variant' => ['style.stylus', true],
            'postcss file' => ['style.postcss', true],
            'pcss file' => ['style.pcss', true],
            'js file' => ['script.js', false],
            'ts file' => ['script.ts', false],
            'image file' => ['image.png', false],
            'no extension' => ['style', false],
        ];
    }

    #[Test]
    #[DataProvider('jsFileProvider')]
    public function isJsFileReturnsCorrectResult(string $fileName, bool $expected): void
    {
        $result = AssetPathUtility::isJsFile($fileName);
        self::assertSame($expected, $result);
    }

    public static function jsFileProvider(): array
    {
        return [
            'plain js' => ['script.js', true],
            'uppercase JS' => ['script.JS', true],
            'mjs file' => ['script.mjs', true],
            'ts file' => ['script.ts', true],
            'tsx file' => ['component.tsx', true],
            'jsx file' => ['component.jsx', true],
            'css file' => ['style.css', false],
            'scss file' => ['style.scss', false],
            'image file' => ['image.png', false],
            'no extension' => ['script', false],
        ];
    }

    #[Test]
    #[DataProvider('imageFileProvider')]
    public function isImageFileReturnsCorrectResult(string $fileName, bool $expected): void
    {
        $result = AssetPathUtility::isImageFile($fileName);
        self::assertSame($expected, $result);
    }

    public static function imageFileProvider(): array
    {
        return [
            'avif file' => ['image.avif', true],
            'webp file' => ['image.webp', true],
            'jpg file' => ['image.jpg', true],
            'jpeg file' => ['image.jpeg', true],
            'png file' => ['image.png', true],
            'gif file' => ['image.gif', true],
            'svg file' => ['icon.svg', true],
            'uppercase PNG' => ['image.PNG', true],
            'css file' => ['style.css', false],
            'js file' => ['script.js', false],
            'no extension' => ['image', false],
        ];
    }

    #[Test]
    #[DataProvider('fontFileProvider')]
    public function isFontFileReturnsCorrectResult(string $fileName, bool $expected): void
    {
        $result = AssetPathUtility::isFontFile($fileName);
        self::assertSame($expected, $result);
    }

    public static function fontFileProvider(): array
    {
        return [
            'woff file' => ['font.woff', true],
            'woff2 file' => ['font.woff2', true],
            'ttf file' => ['font.ttf', true],
            'otf file' => ['font.otf', true],
            'eot file' => ['font.eot', true],
            'uppercase WOFF2' => ['font.WOFF2', true],
            'css file' => ['style.css', false],
            'js file' => ['script.js', false],
            'image file' => ['image.png', false],
            'no extension' => ['font', false],
        ];
    }
}
