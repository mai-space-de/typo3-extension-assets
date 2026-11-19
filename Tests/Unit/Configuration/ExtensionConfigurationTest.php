<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Configuration;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;

final class ExtensionConfigurationTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets']);
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function rootMargins(): array
    {
        return [
            'default' => [null, '0px'],
            'single' => ['200px', '200px'],
            'four values' => ['0px 0px 200px 0px', '0px 0px 200px 0px'],
            'percent' => ['-10% 5.5%', '-10% 5.5%'],
            'script injection' => ["0px';alert(1);'", '0px'],
            'unit missing' => ['200', '0px'],
            'five values' => ['1px 1px 1px 1px 1px', '0px'],
        ];
    }

    #[DataProvider('rootMargins')]
    public function testObserverRootMarginIsValidated(?string $configured, string $expected): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = $configured === null ? [] : ['observerRootMargin' => $configured];

        $configuration = new ExtensionConfiguration(
            (new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor(),
        );

        self::assertSame($expected, $configuration->getObserverRootMargin());
    }

    public function testListSettingsAcceptTheStringFormOfTheExtensionConfiguration(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = [
            'viewportBuckets' => 'small:600, large',
            'criticalThresholdByColPos' => '0:3, 2:1',
            'fontPreloadFormats' => 'WOFF2, woff',
        ];

        $configuration = new ExtensionConfiguration(
            (new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor(),
        );

        self::assertSame(['small' => 600, 'large' => PHP_INT_MAX], $configuration->getViewportBuckets());
        self::assertSame([0 => 3, 2 => 1], $configuration->getCriticalThresholdByColPos());
        self::assertSame(['woff2', 'woff'], $configuration->getFontPreloadFormats());
    }

    public function testArraysFromSettingsPhpStillWork(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = [
            'viewportBuckets' => ['mobile' => 768, 'desktop' => PHP_INT_MAX],
            'criticalThresholdByColPos' => [],
        ];

        $configuration = new ExtensionConfiguration(
            (new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor(),
        );

        self::assertSame(['mobile' => 768, 'desktop' => PHP_INT_MAX], $configuration->getViewportBuckets());
        self::assertSame([], $configuration->getCriticalThresholdByColPos());
        self::assertSame(['woff2'], $configuration->getFontPreloadFormats());
    }
}
