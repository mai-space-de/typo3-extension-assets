<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Command;

use Maispace\MaiAssets\Collector\SvgSpriteCollector;
use Maispace\MaiAssets\Command\WarmupCommand;
use Maispace\MaiAssets\Configuration\ExtensionConfigurationDiscovery;
use Maispace\MaiAssets\Service\CompiledAssetPublisher;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Package\Package;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

final class WarmupCommandTest extends TestCase
{
    private string $extensionPath;

    protected function setUp(): void
    {
        $this->extensionPath = sys_get_temp_dir() . '/mai_warmup_' . uniqid('', true) . '/';
        mkdir($this->extensionPath . 'Configuration', 0777, true);
        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            rtrim($this->extensionPath, '/'),
            $this->extensionPath . 'public',
            $this->extensionPath . 'var',
            $this->extensionPath . 'config',
            $this->extensionPath . 'public/index.php',
            'UNIX',
        );
        file_put_contents($this->extensionPath . 'ok.svg', '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>');
        file_put_contents($this->extensionPath . 'broken.svg', '<svg><path></svg');
        file_put_contents(
            $this->extensionPath . 'Configuration/SpriteIcons.php',
            '<?php return ["icons" => ["ok" => "' . $this->extensionPath . 'ok.svg", "broken" => "' . $this->extensionPath . 'broken.svg"]];',
        );

        $package = $this->createMock(Package::class);
        $package->method('getPackageKey')->willReturn('site_package');
        $package->method('getPackagePath')->willReturn($this->extensionPath);
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn(['site_package' => $package]);
        $packageManager->method('isPackageActive')->willReturn(true);
        $packageManager->method('getPackage')->willReturn($package);
        ExtensionManagementUtility::setPackageManager($packageManager);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->extensionPath));
    }

    public function testInvalidSpriteIconsAreReported(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);
        $discovery = new ExtensionConfigurationDiscovery();

        $tester = new CommandTester(new WarmupCommand(
            $discovery,
            new SvgSpriteCollector($dispatcher, $discovery),
            (new \ReflectionClass(CompiledAssetPublisher::class))->newInstanceWithoutConstructor(),
        ));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('missing in the sprite: broken', $tester->getDisplay());
    }
}
