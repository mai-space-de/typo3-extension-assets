<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Collector;

use Maispace\MaiAssets\Collector\FontPreloadCollector;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Configuration\ExtensionConfigurationDiscovery;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

final class FontPreloadCollectorTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets']);
    }

    public function testPreloadLinkUsesTheWebPathNotTheFileSystemPath(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = ['fontPreloadFormats' => ['woff2']];
        $root = sys_get_temp_dir() . '/mai_assets_fonts_' . uniqid('', true);
        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            $root,
            $root . '/public',
            $root . '/var',
            $root . '/config',
            $root . '/public/index.php',
            'UNIX',
        );

        $earlyHints = new EarlyHintCandidateCollector();
        $subject = new FontPreloadCollector(
            new ExtensionConfiguration((new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor()),
            new ExtensionConfigurationDiscovery(),
            $earlyHints,
        );
        // Skip discovery (needs loaded extensions); register like a Fonts.php entry would.
        (new \ReflectionProperty(FontPreloadCollector::class, 'discovered'))->setValue($subject, true);
        $subject->register('inter', $root . '/public/fonts/inter.woff2');

        $html = $subject->build();

        self::assertStringNotContainsString($root, $html);
        self::assertMatchesRegularExpression('#href="/?fonts/inter\.woff2"#', $html);
        $hint = array_values($earlyHints->getAll())[0];
        self::assertStringEndsWith('fonts/inter.woff2', $hint->href);
        self::assertStringNotContainsString($root, $hint->href);
    }
}
