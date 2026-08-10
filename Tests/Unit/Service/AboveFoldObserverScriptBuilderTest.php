<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Service;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Service\AboveFoldObserverScriptBuilder;
use PHPUnit\Framework\TestCase;

final class AboveFoldObserverScriptBuilderTest extends TestCase
{
    public function testBuildMinifiesAndStripsNewlinesWhenEnabled(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Resources/Public/JavaScript/AboveFoldObserver.js'
        );

        $builder = new AboveFoldObserverScriptBuilder(
            $this->makeConfig(enableMinification: true),
        );

        $result = $builder->build(
            $template,
            35,
            1786307524,
            'abc-token',
            '["mobile","tablet","desktop"]',
        );

        self::assertStringStartsWith('<script>', $result);
        self::assertStringEndsWith('</script>', $result);
        self::assertStringNotContainsString("\n", $result);
        self::assertStringContainsString('var PAGE_UID=35;', $result);
        self::assertStringContainsString('var SERVER_RESET_TIMESTAMP=1786307524;', $result);
        self::assertStringContainsString("var REPORT_TOKEN='abc-token';", $result);
        self::assertStringContainsString('var VALID_BUCKETS=["mobile","tablet","desktop"];', $result);
        self::assertLessThan(strlen($template), strlen($result));
    }

    public function testBuildKeepsFormattingWhenMinificationDisabled(): void
    {
        $template = "var PAGE_UID = ###PAGE_UID###;\nvar x = 1;\n";
        $builder = new AboveFoldObserverScriptBuilder(
            $this->makeConfig(enableMinification: false),
        );

        $result = $builder->build($template, 10, 1, 'tok', '[]');

        self::assertStringContainsString("\n", $result);
        self::assertStringContainsString('var PAGE_UID = 10;', $result);
    }

    private function makeConfig(bool $enableMinification): ExtensionConfiguration
    {
        $config = new \ReflectionClass(ExtensionConfiguration::class)
            ->newInstanceWithoutConstructor();
        new \ReflectionProperty(ExtensionConfiguration::class, 'enableMinification')
            ->setValue($config, $enableMinification);

        return $config;
    }
}
