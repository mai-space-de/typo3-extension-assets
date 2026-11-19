<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\ImageMeasurement;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementProfileResolver;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementService;
use Maispace\MaiAssets\Security\ImageMeasureTokenService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;

final class ImageMeasurementServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'test-key';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = ['enableImageMeasurement' => true];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'], $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets']);
    }

    public function testImagesWithoutElementOrSlotAreNotMeasured(): void
    {
        // The same file in header, footer and content would mix its widths.
        self::assertNull($this->service()->prepare($this->file(), 0, 1));
        self::assertNull($this->service()->prepare($this->file(), 0, 1, '  '));
    }

    public function testImagesOfAnElementOrSlotAreMeasured(): void
    {
        self::assertSame('e12.f5', $this->service()->prepare($this->file(), 12, 1)?->key);
        self::assertSame('s.header-logo', $this->service()->prepare($this->file(), 0, 1, 'header-logo')?->key);
    }

    private function file(): object
    {
        return new class {
            public function getUid(): int
            {
                return 5;
            }
        };
    }

    private function service(): ImageMeasurementService
    {
        $resolver = $this->createMock(ImageMeasurementProfileResolver::class);
        $resolver->method('resolve')->willReturn(null);

        return new ImageMeasurementService(
            new ExtensionConfiguration((new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor()),
            $resolver,
            new ImageMeasureTokenService(),
        );
    }
}
