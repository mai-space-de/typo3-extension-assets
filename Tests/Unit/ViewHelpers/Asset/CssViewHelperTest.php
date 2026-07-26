<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\ViewHelpers\Asset;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\Processing\MinificationProcessor;
use Maispace\MaiAssets\Processing\ScssProcessor;
use Maispace\MaiAssets\Service\AssetCriticalityResolver;
use Maispace\MaiAssets\Service\CompiledAssetPublisher;
use Maispace\MaiAssets\Service\CriticalDetectionService;
use Maispace\MaiAssets\Service\SriHashService;
use Maispace\MaiAssets\ViewHelpers\Asset\CssViewHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Routing\PageArguments;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Covers the `critical` parameter resolution logic added in PLAN.md step 14/15.
 *
 * Every class in mai_assets is `final`, so mocks are impossible.  Instead we
 * create "bare" instances via ReflectionClass::newInstanceWithoutConstructor()
 * and use ReflectionProperty to inject the one or two inner dependencies that
 * control the decision paths.
 *
 * CompiledAssetPublisher has a short-circuit: if the source is plain CSS and
 * minification is disabled, publishStylesheet() returns the source path as-is.
 * We exploit that to avoid touching the filesystem beyond creating a temp .css
 * file in setUp().
 */
final class CssViewHelperTest extends TestCase
{
    private const CSS_CONTENT = 'body{color:red}';

    private string $tempCssFile;
    private string $publicDir;

    /** @var AssetCollector&MockObject */
    private AssetCollector $assetCollector;
    /** @var EventDispatcherInterface&MockObject */
    private EventDispatcherInterface $eventDispatcher;

    private ExtensionConfiguration $extensionConfiguration;
    private CompiledAssetPublisher $compiledAssetPublisher;
    private ScssProcessor $scssProcessor;
    private MinificationProcessor $minificationProcessor;
    private SriHashService $sriHashService;
    private AssetCriticalityResolver $criticalityResolver;
    private EarlyHintCandidateCollector $earlyHintCollector;

    /** @var FrontendInterface&MockObject */
    private FrontendInterface $cacheFrontend;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir();
        $this->publicDir = $tmp . '/mai_assets_test_public';
        $this->recreateDir($this->publicDir);

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            $tmp . '/mai_assets_test_project',
            $this->publicDir,
            $tmp . '/mai_assets_test_var',
            $tmp . '/mai_assets_test_config',
            $this->publicDir . '/index.php',
            'UNIX',
        );

        $this->tempCssFile = $this->publicDir . '/test.css';
        file_put_contents($this->tempCssFile, self::CSS_CONTENT);

        // Mocks (only the two non-final dependencies)
        $this->assetCollector = $this->createMock(AssetCollector::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

        // ExtensionConfiguration (final)
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = [
            'enableScssProcessing'  => false,
            'enableMinification'    => false,
            'enableCompression'     => false,
            'compressionLevel'      => 6,
            'enableBrotli'          => false,
            'criticalThresholdByColPos' => [0 => 2, 1 => 0, 3 => 0],
            'viewportBuckets'       => ['mobile' => 768, 'tablet' => 1024, 'desktop' => 99999],
            'svgStripAttributes'    => ['id', 'class', 'style'],
            'fontPreloadFormats'    => ['woff2'],
            'observerRootMargin'    => '200px',
            'processingCacheLifetime' => 0,
        ];
        $this->extensionConfiguration = new ExtensionConfiguration(
            $this->noConstructor(Typo3ExtensionConfiguration::class)
        );

        // Processors (final, never invoked for plain CSS)
        $this->scssProcessor = $this->noConstructor(ScssProcessor::class);
        $this->minificationProcessor = $this->noConstructor(MinificationProcessor::class);

        // CompiledAssetPublisher (final)
        // Short-circuit path: !scss && !minify => returns source path unchanged
        $this->compiledAssetPublisher = $this->noConstructor(CompiledAssetPublisher::class);
        $this->setProp($this->compiledAssetPublisher, 'scssProcessor', $this->scssProcessor);
        $this->setProp($this->compiledAssetPublisher, 'minificationProcessor', $this->minificationProcessor);
        $this->setProp($this->compiledAssetPublisher, 'extensionConfiguration', $this->extensionConfiguration);
        $this->setProp($this->compiledAssetPublisher, 'scssDependencyHasher', new \Maispace\MaiAssets\Service\ScssDependencyHasher());

        // SriHashService (final, unused — we supply integrity)
        $this->sriHashService = $this->noConstructor(SriHashService::class);

        // AssetCriticalityResolver (final)
        // Controls pageHasObserverData() via an injected mock cache frontend.
        $this->cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheService = $this->noConstructor(AboveFoldCacheService::class);
        $this->setProp($cacheService, 'cache', $this->cacheFrontend);
        $this->setProp($cacheService, 'cacheManager', $this->createMock(\TYPO3\CMS\Core\Cache\CacheManager::class));
        $this->setProp($cacheService, 'eventDispatcher', $this->createMock(\Psr\EventDispatcher\EventDispatcherInterface::class));

        $detectionService = $this->noConstructor(CriticalDetectionService::class);
        $this->criticalityResolver = new AssetCriticalityResolver($cacheService, $detectionService, $this->extensionConfiguration);

        // EarlyHintCandidateCollector (final)
        // No-constructor; verify via getAll() after the VH runs.
        $this->earlyHintCollector = $this->noConstructor(EarlyHintCandidateCollector::class);

        $this->setUpPageRequest(0);
    }

    protected function tearDown(): void
    {
        @unlink($this->tempCssFile);
        unset($GLOBALS['TYPO3_REQUEST']);
    }

    public function testCriticalTrueReturnsInlineStyleTag(): void
    {
        $this->assetCollector->expects(self::never())->method('addStyleSheet');
        $this->eventDispatcher->expects(self::once())->method('dispatch');

        $result = $this->render(['critical' => 'true']);

        self::assertStringContainsString('<style>', $result);
        self::assertStringContainsString(self::CSS_CONTENT, $result);
        self::assertStringContainsString('</style>', $result);

        // early hint should have been registered
        self::assertCount(1, $this->earlyHintCollector->getAll());
    }

    public function testCriticalTrueIncludesNonceAttributeWhenProvided(): void
    {
        $this->eventDispatcher->expects(self::once())->method('dispatch');

        $result = $this->render(['critical' => 'true', 'nonce' => 'abc123']);

        self::assertStringContainsString(' nonce="abc123"', $result);
    }

    public function testCriticalTrueWithoutNonceDoesNotEmitNonceAttribute(): void
    {
        $this->eventDispatcher->expects(self::once())->method('dispatch');

        $result = $this->render(['critical' => 'true', 'nonce' => '']);

        self::assertStringNotContainsString(' nonce="', $result);
    }

    public function testCriticalFalseDelegatesToAssetCollector(): void
    {
        $this->assetCollector->expects(self::once())
            ->method('addStyleSheet')
            ->with('main-css', self::anything(), self::anything(), self::anything());
        $this->eventDispatcher->expects(self::never())->method('dispatch');

        $result = $this->render(['critical' => 'false']);

        self::assertSame('', $result);
        self::assertCount(1, $this->earlyHintCollector->getAll());
    }

    public function testCriticalAutoInlinesWhenObserverDataExists(): void
    {
        $this->setUpPageRequest(42);
        $this->injectObserverData(pageUid: 42, uids: [1, 2, 3]);

        $this->assetCollector->expects(self::never())->method('addStyleSheet');
        $this->eventDispatcher->expects(self::once())->method('dispatch');

        $result = $this->render(['critical' => 'auto']);

        self::assertStringContainsString('<style>', $result);
    }

    public function testCriticalAutoUsesExternalWhenNoObserverData(): void
    {
        $this->setUpPageRequest(42);
        $this->injectObserverData(pageUid: 42, uids: []);

        $this->assetCollector->expects(self::once())->method('addStyleSheet');
        $this->eventDispatcher->expects(self::never())->method('dispatch');

        $result = $this->render(['critical' => 'auto']);

        self::assertSame('', $result);
    }

    public function testCriticalAutoFallsBackToExternalWhenPageUidIsZero(): void
    {
        $this->assetCollector->expects(self::once())->method('addStyleSheet');
        $this->eventDispatcher->expects(self::never())->method('dispatch');

        $result = $this->render(['critical' => 'auto']);

        self::assertSame('', $result);
    }

    private function render(array $overrides): string
    {
        $vh = $this->createViewHelper();
        $vh->setArguments(array_merge([
            'identifier'  => 'main-css',
            'src'         => $this->tempCssFile,
            'critical'    => 'auto',
            'priority'    => false,
            'minify'      => null,
            'media'       => 'all',
            'nonce'       => '',
            'integrity'   => '',
            'crossorigin' => '',
        ], $overrides));

        return $vh->render();
    }

    private function createViewHelper(): CssViewHelper
    {
        return new CssViewHelper(
            $this->scssProcessor,
            $this->minificationProcessor,
            $this->compiledAssetPublisher,
            $this->sriHashService,
            $this->assetCollector,
            $this->extensionConfiguration,
            $this->eventDispatcher,
            $this->criticalityResolver,
            $this->earlyHintCollector,
        );
    }

    private function setUpPageRequest(int $pageUid): void
    {
        $pageArguments = $this->createMock(PageArguments::class);
        $pageArguments->method('getPageId')->willReturn($pageUid);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('routing')
            ->willReturn($pageArguments);

        $GLOBALS['TYPO3_REQUEST'] = $request;
    }

    /**
     * Seed the cache frontend so pageHasObserverData() returns the desired result.
     *
     * @param list<int> $uids  empty → false, non-empty → true
     */
    private function injectObserverData(int $pageUid, array $uids): void
    {
        if ($uids === []) {
            // No bucket index → getAllCriticalUids returns []
            $this->cacheFrontend->method('get')->willReturn(false);
        } else {
            $this->cacheFrontend->method('get')->willReturnMap([
                ['buckets_' . $pageUid, ['desktop']],
                ['page_' . $pageUid . '_desktop', $uids],
            ]);
        }
    }

    /** @template T of object */
    private function noConstructor(string $class): object
    {
        /** @var T */
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function setProp(object $target, string $property, mixed $value): void
    {
        $prop = new \ReflectionProperty($target::class, $property);
        $prop->setAccessible(true);
        $prop->setValue($target, $value);
    }

    private function recreateDir(string $path): void
    {
        if (is_dir($path)) {
            $this->rmDirRecursive($path);
        }
        mkdir($path, 0777, true);
    }

    private function rmDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rmDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
