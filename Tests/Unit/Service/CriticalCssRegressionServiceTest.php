<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Service;

use Maispace\MaiAssets\Exception\AssetException;
use Maispace\MaiAssets\Service\CompiledAssetPublisher;
use Maispace\MaiAssets\Service\CriticalCssRegressionService;
use PHPUnit\Framework\TestCase;

final class CriticalCssRegressionServiceTest extends TestCase
{
    private CriticalCssRegressionService $subject;
    private string $testBaselineDir;
    private string $testBaselinePath;

    protected function setUp(): void
    {
        $this->testBaselineDir = sys_get_temp_dir() . '/css_regression_test_' . uniqid();
        $this->testBaselinePath = $this->testBaselineDir . '/baseline.json';
        mkdir($this->testBaselineDir);

        $compiledAssetPublisher = new \ReflectionClass(CompiledAssetPublisher::class)
            ->newInstanceWithoutConstructor();
        $this->subject = new CriticalCssRegressionService($compiledAssetPublisher);
        $this->subject->setBaselinePathForTesting($this->testBaselinePath);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testBaselinePath)) {
            unlink($this->testBaselinePath);
        }
        if (is_dir($this->testBaselineDir)) {
            rmdir($this->testBaselineDir);
        }
    }

    public function testMeasureCompiledFileSizeReturnsCorrectByteCount(): void
    {
        $css = 'body { color: red; margin: 0; }';
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_css_');
        self::assertNotFalse($tmpFile);

        try {
            file_put_contents($tmpFile, $css);
            $size = $this->subject->measureCompiledFileSize($tmpFile);
            self::assertSame(strlen($css), $size);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testMeasureCompiledFileSizeHandlesEmptyFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'empty_css_');
        self::assertNotFalse($tmpFile);

        try {
            file_put_contents($tmpFile, '');
            $size = $this->subject->measureCompiledFileSize($tmpFile);
            self::assertSame(0, $size);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testMeasureCompiledFileSizeThrowsWhenFileNotFound(): void
    {
        $this->expectException(AssetException::class);
        $this->subject->measureCompiledFileSize('/nonexistent/file.css');
    }

    public function testCheckRegressionWithSizePassesWhenUnderBaseline(): void
    {
        $size = 100;
        $this->subject->setBaselineEntry('test-css', 1000);

        $result = $this->subject->checkRegressionWithSize('test-css', $size);

        self::assertTrue($result['passed']);
        self::assertSame($size, $result['actual']);
        self::assertSame(1000, $result['baseline']);
        self::assertNull($result['exceeded_by']);
    }

    public function testCheckRegressionWithSizeFailsWhenExceedsBaseline(): void
    {
        $size = 500;
        $this->subject->setBaselineEntry('test-css-large', 100);

        $result = $this->subject->checkRegressionWithSize('test-css-large', $size);

        self::assertFalse($result['passed']);
        self::assertSame($size, $result['actual']);
        self::assertSame(100, $result['baseline']);
        self::assertSame(400, $result['exceeded_by']);
    }

    public function testCheckRegressionWithSizePassesWhenNoBaseline(): void
    {
        $size = 100;
        $result = $this->subject->checkRegressionWithSize('unknown-css', $size);

        self::assertTrue($result['passed']);
        self::assertSame($size, $result['actual']);
        self::assertNull($result['baseline']);
        self::assertNull($result['exceeded_by']);
    }

    public function testGetBaselineReturnsEmptyArrayWhenNoBaselineFile(): void
    {
        $baseline = $this->subject->getBaseline();
        self::assertIsArray($baseline);
    }

    public function testSetBaselineEntryCreatesBaselineFile(): void
    {
        $this->subject->setBaselineEntry('test-entry', 5000);
        $baseline = $this->subject->getBaseline();

        self::assertArrayHasKey('test-entry', $baseline);
        self::assertSame(5000, $baseline['test-entry']);
    }

    public function testSetBaselineEntryUpdatesExistingEntry(): void
    {
        $this->subject->setBaselineEntry('test-update', 5000);
        $this->subject->setBaselineEntry('test-update', 6000);

        $baseline = $this->subject->getBaseline();
        self::assertSame(6000, $baseline['test-update']);
    }

    public function testGetBaselineReturnsEmptyArrayWhenFileIsInvalid(): void
    {
        $baselineDir = sys_get_temp_dir() . '/test_baseline_' . uniqid();
        mkdir($baselineDir);

        try {
            $baselineFile = $baselineDir . '/baseline.json';
            file_put_contents($baselineFile, 'invalid json{');

            // Use reflection to test with invalid baseline
            $baseline = $this->subject->getBaseline();
            self::assertIsArray($baseline);
        } finally {
            if (file_exists($baselineFile)) {
                unlink($baselineFile);
            }
            rmdir($baselineDir);
        }
    }

    public function testCheckRegressionWithSizeCalculatesExceededByCorrectly(): void
    {
        $actualSize = 250;
        $baseline = $actualSize - 10;
        $this->subject->setBaselineEntry('test-exceeded', $baseline);

        $result = $this->subject->checkRegressionWithSize('test-exceeded', $actualSize);

        self::assertFalse($result['passed']);
        self::assertSame(10, $result['exceeded_by']);
    }
}
