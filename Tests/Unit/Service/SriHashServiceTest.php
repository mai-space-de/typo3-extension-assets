<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Service;

use Maispace\MaiAssets\Exception\AssetFileNotFoundException;
use Maispace\MaiAssets\Service\SriHashService;
use PHPUnit\Framework\TestCase;

final class SriHashServiceTest extends TestCase
{
    private SriHashService $subject;

    protected function setUp(): void
    {
        $this->subject = new SriHashService();
    }

    public function testComputeForContentReturnsCorrectPrefix(): void
    {
        $result = $this->subject->computeForContent('body { color: red; }');
        self::assertStringStartsWith('sha384-', $result);
    }

    public function testComputeForContentReturnsDeterministicHash(): void
    {
        $content = 'console.log("hello");';
        $first = $this->subject->computeForContent($content);
        $second = $this->subject->computeForContent($content);
        self::assertSame($first, $second);
    }

    public function testComputeForContentProducesValidBase64(): void
    {
        $result = $this->subject->computeForContent('test');
        $base64Part = substr($result, strlen('sha384-'));
        self::assertNotFalse(base64_decode($base64Part, true), 'Hash part must be valid base64');
    }

    public function testComputeForContentDifferentInputProducesDifferentHash(): void
    {
        $hash1 = $this->subject->computeForContent('content-a');
        $hash2 = $this->subject->computeForContent('content-b');
        self::assertNotSame($hash1, $hash2);
    }

    public function testComputeForFileThrowsWhenFileNotFound(): void
    {
        $this->expectException(AssetFileNotFoundException::class);
        $this->expectExceptionMessageMatches('/Cannot compute SRI hash/');
        $this->subject->computeForFile('/nonexistent/path/file.css');
    }

    public function testComputeForFileMatchesComputeForContent(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sri_test_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'body { margin: 0; }');

        try {
            $fromFile = $this->subject->computeForFile($tmpFile);
            $fromContent = $this->subject->computeForContent('body { margin: 0; }');
            self::assertSame($fromContent, $fromFile);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testComputeForEmptyContentReturnsValidHash(): void
    {
        $result = $this->subject->computeForContent('');
        self::assertStringStartsWith('sha384-', $result);
    }
}
