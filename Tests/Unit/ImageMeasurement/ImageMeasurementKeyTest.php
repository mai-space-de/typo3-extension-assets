<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\ImageMeasurement;

use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementKey;
use Maispace\MaiAssets\Security\ImageMeasureTokenService;
use PHPUnit\Framework\TestCase;

final class ImageMeasurementKeyTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-encryption-key';
    }

    public function testValidatesKeyFormat(): void
    {
        self::assertTrue(ImageMeasurementKey::isValid('e12.r34'));
        self::assertTrue(ImageMeasurementKey::isValid('e0.f5'));
        self::assertTrue(ImageMeasurementKey::isValid('s.textmedia-left'));
        foreach (['s.', 's.Left', 's.a b', "s.a\n", 's.' . str_repeat('a', 33), '', 'e12', 'e12.x3', "e1.r2'; DROP", 'e12.r34 ', 'E1.r2', 'e12345678901.r1'] as $invalid) {
            self::assertFalse(ImageMeasurementKey::isValid($invalid), $invalid);
        }
    }

    public function testBuildsKeyFromFileReferenceAndFile(): void
    {
        $reference = new class () {
            public function getUid(): int { return 34; }
            public function getOriginalFile(): object { return new \stdClass(); }
        };
        $file = new class () {
            public function getUid(): int { return 5; }
        };

        self::assertSame('e12.r34', ImageMeasurementKey::fromImage($reference, 12));
        self::assertSame('e0.f5', ImageMeasurementKey::fromImage($file, 0));
        self::assertNull(ImageMeasurementKey::fromImage(new \stdClass(), 1));
        self::assertSame('s.textmedia-left', ImageMeasurementKey::fromImage($file, 12, ' TextMedia-Left '));
        self::assertSame('e12.r34', ImageMeasurementKey::fromImage($reference, 12, 'invalid slot!'));
        self::assertSame(['type' => 'r', 'uid' => 34], ImageMeasurementKey::parse('e12.r34'));
    }

    public function testTokenIsBoundToPageAndKey(): void
    {
        $service = new ImageMeasureTokenService();
        $token = $service->generate(7, 'e1.r2');

        self::assertSame(32, strlen($token));
        self::assertTrue($service->verify($token, 7, 'e1.r2'));
        self::assertFalse($service->verify($token, 8, 'e1.r2'));
        self::assertFalse($service->verify($token, 7, 'e1.r3'));
        self::assertFalse($service->verify('', 7, 'e1.r2'));
    }
}
