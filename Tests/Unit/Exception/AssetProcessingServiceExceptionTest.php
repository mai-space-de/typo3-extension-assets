<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Tests\Unit\Exception;

use Maispace\MaispaceAssets\Cache\AssetCacheManager;
use Maispace\MaispaceAssets\Exception\AssetCompilationException;
use Maispace\MaispaceAssets\Exception\AssetException;
use Maispace\MaispaceAssets\Exception\AssetFileNotFoundException;
use Maispace\MaispaceAssets\Exception\AssetWriteException;
use Maispace\MaispaceAssets\Exception\InvalidAssetConfigurationException;
use Maispace\MaispaceAssets\Exception\InvalidImageInputException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the exception class hierarchy: inheritance, interfaces, and
 * that each exception can be constructed and carries its message correctly.
 */
final class AssetProcessingServiceExceptionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Hierarchy
    // -------------------------------------------------------------------------

    public function testAssetFileNotFoundExceptionExtendsAssetException(): void
    {
        $e = new AssetFileNotFoundException('file not found');

        self::assertInstanceOf(AssetException::class, $e);
        self::assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testAssetCompilationExceptionExtendsAssetException(): void
    {
        $e = new AssetCompilationException('compile failed');

        self::assertInstanceOf(AssetException::class, $e);
        self::assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testAssetWriteExceptionExtendsAssetException(): void
    {
        $e = new AssetWriteException('write failed');

        self::assertInstanceOf(AssetException::class, $e);
        self::assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testInvalidAssetConfigurationExceptionExtendsInvalidArgumentException(): void
    {
        $e = new InvalidAssetConfigurationException('bad config');

        self::assertInstanceOf(\InvalidArgumentException::class, $e);
    }

    public function testInvalidImageInputExceptionExtendsInvalidArgumentException(): void
    {
        $e = new InvalidImageInputException('bad image');

        self::assertInstanceOf(\InvalidArgumentException::class, $e);
    }

    // -------------------------------------------------------------------------
    // Exception chaining
    // -------------------------------------------------------------------------

    public function testAssetFileNotFoundExceptionPreservesPreviousException(): void
    {
        $original = new \RuntimeException('original');
        $wrapped = new AssetFileNotFoundException('wrapped', 0, $original);

        self::assertSame($original, $wrapped->getPrevious());
        self::assertSame('wrapped', $wrapped->getMessage());
    }

    public function testAssetCompilationExceptionPreservesPreviousException(): void
    {
        $sass = new \RuntimeException('syntax error');
        $wrapped = new AssetCompilationException('SCSS failed', 0, $sass);

        self::assertSame($sass, $wrapped->getPrevious());
    }

    // -------------------------------------------------------------------------
    // AssetException catches subclasses (catch by base type works)
    // -------------------------------------------------------------------------

    public function testCatchingBaseAssetExceptionCatchesFileNotFound(): void
    {
        $caught = null;
        try {
            throw new AssetFileNotFoundException('test');
        } catch (AssetException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(AssetFileNotFoundException::class, $caught);
    }

    public function testCatchingBaseAssetExceptionCatchesCompilationException(): void
    {
        $caught = null;
        try {
            throw new AssetCompilationException('test');
        } catch (AssetException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(AssetCompilationException::class, $caught);
    }

    public function testCatchingBaseAssetExceptionCatchesWriteException(): void
    {
        $caught = null;
        try {
            throw new AssetWriteException('test');
        } catch (AssetException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(AssetWriteException::class, $caught);
    }

    // -------------------------------------------------------------------------
    // AssetCacheManager is mockable (not final)
    // -------------------------------------------------------------------------

    public function testAssetCacheManagerIsMockable(): void
    {
        $mock = $this->createMock(AssetCacheManager::class);

        self::assertNotNull($mock);
    }
}
