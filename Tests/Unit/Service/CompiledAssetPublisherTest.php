<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Service;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Processing\MinificationProcessor;
use Maispace\MaiAssets\Processing\ScssProcessor;
use Maispace\MaiAssets\Service\CompiledAssetPublisher;
use Maispace\MaiAssets\Service\ScssDependencyHasher;
use Maispace\MaiAssets\Utility\AssetPathUtility;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

final class CompiledAssetPublisherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mai_assets_publisher_' . uniqid('', true);
        mkdir($this->root . '/public', 0777, true);
        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            $this->root,
            $this->root . '/public',
            $this->root . '/var',
            $this->root . '/config',
            $this->root . '/public/index.php',
            'UNIX',
        );
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = ['enableMinification' => false, 'enableScssProcessing' => false];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets']);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testPublicPlainCssIsLinkedDirectly(): void
    {
        $file = $this->file('public/css/site.css', 'a{}');

        self::assertSame($file, $this->publisher()->publishStylesheet($file));
    }

    public function testCssOutsideThePublicDirectoryIsCopiedWithAbsoluteUrls(): void
    {
        // Linking the file system path of Resources/Private returned 404.
        $file = $this->file('vendor/acme/theme/Resources/Private/Css/site.css', '.a{background:url(../../Public/Images/bg.png)}');

        $published = $this->publisher()->publishStylesheet($file);

        self::assertNotSame($file, $published);
        self::assertTrue(AssetPathUtility::isPubliclyReachable($published));
        self::assertStringContainsString(
            'url("_assets/' . md5('/vendor/acme/theme/') . '/Images/bg.png")',
            (string) file_get_contents($published),
        );
    }

    public function testMissingTargetInAPublicLocationIsStillMadeAbsolute(): void
    {
        // Left relative it would point into typo3temp/assets/mai_assets/compiled/.
        $file = $this->file('vendor/acme/theme/Resources/Public/Css/site.css', '.a{background:url(../Images/later.png)}');

        $css = $this->publisher()->getInlineStylesheet($file);

        self::assertStringContainsString('url("_assets/' . md5('/vendor/acme/theme/') . '/Images/later.png")', $css);
    }

    public function testPathRepositoryPackagesUseTheirVendorAssetsPath(): void
    {
        // packages/theme is installed as vendor/acme/theme (symlink); _assets uses the vendor path.
        $this->file('packages/theme/composer.json', '{"name": "acme/theme"}');
        mkdir($this->root . '/vendor/acme/theme', 0777, true);
        $this->file('packages/theme/Resources/Public/Images/bg.png', 'png');
        $file = $this->file('packages/theme/Resources/Public/Css/site.css', '.a{background:url(../Images/bg.png)}');

        $css = $this->publisher()->getInlineStylesheet($file);

        self::assertStringContainsString('url("_assets/' . md5('/vendor/acme/theme/') . '/Images/bg.png")', $css);
    }

    public function testPublicReachability(): void
    {
        self::assertTrue(AssetPathUtility::isPubliclyReachable($this->root . '/public/fileadmin/a.css'));
        self::assertTrue(AssetPathUtility::isPubliclyReachable($this->root . '/vendor/a/b/Resources/Public/a.css'));
        self::assertFalse(AssetPathUtility::isPubliclyReachable($this->root . '/vendor/a/b/Resources/Private/a.css'));
        self::assertFalse(AssetPathUtility::isPubliclyReachable('/etc/a.css'));
    }

    private function file(string $relativePath, string $content): string
    {
        $path = $this->root . '/' . $relativePath;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0777, true);
        }
        file_put_contents($path, $content);

        return $path;
    }

    private function publisher(): CompiledAssetPublisher
    {
        $configuration = new ExtensionConfiguration((new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor());
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        return new CompiledAssetPublisher(
            new ScssProcessor($dispatcher, $configuration, new ScssDependencyHasher()),
            new MinificationProcessor($dispatcher, $configuration),
            $configuration,
            new ScssDependencyHasher(),
        );
    }
}
