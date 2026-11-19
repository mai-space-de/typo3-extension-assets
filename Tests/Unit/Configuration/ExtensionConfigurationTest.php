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
}
