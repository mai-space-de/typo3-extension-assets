<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\ViewHelpers\Asset;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\Processing\MinificationProcessor;
use Maispace\MaiAssets\Service\AssetCriticalityResolver;
use Maispace\MaiAssets\Service\CriticalDetectionService;
use Maispace\MaiAssets\Service\SriHashService;
use Maispace\MaiAssets\ViewHelpers\Asset\JsViewHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Page\AssetCollector;

final class JsViewHelperTest extends TestCase
{
    private string $root;
    private string $jsFile;
    /** @var AssetCollector&MockObject */
    private AssetCollector $assetCollector;
    private EarlyHintCandidateCollector $earlyHints;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mai_assets_js_' . uniqid('', true);
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
        $this->jsFile = $this->root . '/public/app.js';
        file_put_contents($this->jsFile, 'console.log(1);');

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = [
            'enableMinification' => false,
            'useDevServer' => '0',
        ];
        $this->assetCollector = $this->createMock(AssetCollector::class);
        $this->earlyHints = new EarlyHintCandidateCollector();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'], $GLOBALS['TYPO3_REQUEST']);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testCriticalScriptStaysDeferred(): void
    {
        // Regression: critical scripts lost "defer" and became parser/render blocking.
        $attributes = null;
        $this->assetCollector->expects(self::once())->method('addJavaScript')
            ->willReturnCallback(function (string $identifier, string $source, array $tagAttributes) use (&$attributes): AssetCollector {
                $attributes = $tagAttributes;

                return $this->assetCollector;
            });

        $this->render(critical: 'true');

        self::assertSame('defer', $attributes['defer'] ?? null);
        self::assertSame('high', $attributes['fetchpriority'] ?? null);
        self::assertCount(1, $this->earlyHints->getAll());
    }

    public function testNonCriticalScriptIsDeferredWithoutPriority(): void
    {
        $attributes = null;
        $this->assetCollector->method('addJavaScript')
            ->willReturnCallback(function (string $identifier, string $source, array $tagAttributes) use (&$attributes): AssetCollector {
                $attributes = $tagAttributes;

                return $this->assetCollector;
            });

        $this->render(critical: 'false');

        self::assertSame('defer', $attributes['defer'] ?? null);
        self::assertArrayNotHasKey('fetchpriority', $attributes);
        self::assertCount(0, $this->earlyHints->getAll());
    }

    public function testScriptOutsideThePublicDirectoryIsPublished(): void
    {
        $private = $this->root . '/vendor/acme/theme/Resources/Private/JavaScript/app.js';
        mkdir(\dirname($private), 0777, true);
        file_put_contents($private, 'console.log(2);');
        $this->jsFile = $private;

        $source = null;
        $this->assetCollector->method('addJavaScript')
            ->willReturnCallback(function (string $identifier, string $src) use (&$source): AssetCollector {
                $source = $src;

                return $this->assetCollector;
            });

        $this->render(critical: 'false');

        self::assertStringContainsString('typo3temp/assets/mai_assets/compiled/' . md5('console.log(2);') . '.js', (string) $source);
    }

    public function testIntegrityNoneDisablesSri(): void
    {
        $attributes = null;
        $this->assetCollector->method('addJavaScript')
            ->willReturnCallback(function (string $identifier, string $source, array $tagAttributes) use (&$attributes): AssetCollector {
                $attributes = $tagAttributes;

                return $this->assetCollector;
            });

        $this->render(critical: 'false', integrity: 'none');

        self::assertArrayNotHasKey('integrity', $attributes);
    }

    private function render(string $critical, string $integrity = ''): void
    {
        $extensionConfiguration = new ExtensionConfiguration(
            (new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor(),
        );
        $cacheService = (new \ReflectionClass(AboveFoldCacheService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AboveFoldCacheService::class, 'cache'))->setValue($cacheService, $this->createMock(FrontendInterface::class));
        $resolver = new AssetCriticalityResolver(
            $cacheService,
            (new \ReflectionClass(CriticalDetectionService::class))->newInstanceWithoutConstructor(),
            $extensionConfiguration,
        );

        $viewHelper = new JsViewHelper(
            (new \ReflectionClass(MinificationProcessor::class))->newInstanceWithoutConstructor(),
            new SriHashService(),
            $this->assetCollector,
            $extensionConfiguration,
            $this->createMock(EventDispatcherInterface::class),
            $resolver,
            $this->earlyHints,
        );
        $viewHelper->setArguments([
            'identifier' => 'app',
            'src' => $this->jsFile,
            'priority' => true,
            'minify' => false,
            'critical' => $critical,
            'defer' => true,
            'async' => false,
            'type' => '',
            'nomodule' => false,
            'nonce' => '',
            'integrity' => $integrity,
            'crossorigin' => '',
        ]);
        $viewHelper->render();
    }
}
