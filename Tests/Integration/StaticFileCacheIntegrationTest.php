<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Integration;

use Psr\EventDispatcher\EventDispatcherInterface;
use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidate;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\Middleware\EarlyHintsMiddleware;
use Maispace\MaiAssets\Middleware\StaticFileServeMiddleware;
use Maispace\MaiAssets\StaticFileCache\StaticFileCacheDirectory;
use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use Maispace\MaiAssets\StaticFileCache\StaticHtmlWriterService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Integration tests for static file cache services and middleware.
 *
 * Uses real TransientMemoryBackend cache stacks and a real temporary
 * filesystem for HTML output. Complements StaticFileCacheReadinessIntegrationTest
 * and the unit tests (which mock cache frontends).
 */
final class StaticFileCacheIntegrationTest extends TestCase
{
    /** Site language UIDs: de, en, uk, ar */
    private const array LANGUAGE_UIDS = [0, 1, 2, 3];

    private string $tempBaseDir;
    private TransientMemoryBackend $earlyHintBackend;
    private VariableFrontend $earlyHintFrontend;
    private EarlyHintCacheService $earlyHintCacheService;
    private StaticFileCacheDirectory $cacheDirectory;
    private ExtensionConfiguration $extensionConfiguration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBaseDir = sys_get_temp_dir() . '/mai_assets_sfc_int_' . uniqid('', true);
        mkdir($this->tempBaseDir, 0777, true);

        Environment::initialize(
            new ApplicationContext('Production'),
            true,
            true,
            $this->tempBaseDir,
            $this->tempBaseDir . '/public',
            $this->tempBaseDir . '/var',
            $this->tempBaseDir . '/config',
            $this->tempBaseDir . '/public/index.php',
            'UNIX',
        );

        mkdir($this->tempBaseDir . '/public/typo3temp/assets/mai_assets_static', 0777, true);

        $this->earlyHintBackend = new TransientMemoryBackend([]);
        $this->earlyHintFrontend = new VariableFrontend(
            'mai_assets_early_hints',
            $this->earlyHintBackend,
        );

        $earlyHintCacheManager = $this->createMock(CacheManager::class);
        $earlyHintCacheManager
            ->method('getCache')
            ->with('mai_assets_early_hints')
            ->willReturn($this->earlyHintFrontend);
        $this->earlyHintCacheService = new EarlyHintCacheService($earlyHintCacheManager);

        $this->extensionConfiguration = $this->createExtensionConfiguration(
            enableStaticFileCache: true,
            enableCompression: true,
            enableBrotli: true,
        );
        $this->cacheDirectory = new StaticFileCacheDirectory($this->extensionConfiguration);
    }

    protected function tearDown(): void
    {
        $this->earlyHintFrontend->flush();
        $this->removeDirectory($this->tempBaseDir);
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // EarlyHintCacheService — store/load with real TYPO3 cache
    // -----------------------------------------------------------------

    public function testEarlyHintStoreAndLoadRoundTrip(): void
    {
        $collector = new EarlyHintCandidateCollector();
        $collector->add(new EarlyHintCandidate('/assets/app.css', 'preload', 'style'));
        $collector->add(new EarlyHintCandidate('/assets/app.js', 'modulepreload', 'script'));

        $this->earlyHintCacheService->store(100, 0, $collector);

        $loaded = $this->earlyHintCacheService->load(100, 0);

        self::assertCount(2, $loaded);
        self::assertSame('/assets/app.css', $loaded[0]->href);
        self::assertSame('preload', $loaded[0]->rel);
        self::assertSame('style', $loaded[0]->as);
        self::assertSame('/assets/app.js', $loaded[1]->href);
    }

    public function testEarlyHintManifestIsolatedPerLanguage(): void
    {
        foreach (self::LANGUAGE_UIDS as $languageUid) {
            $collector = new EarlyHintCandidateCollector();
            $collector->add(new EarlyHintCandidate(
                '/assets/lang-' . $languageUid . '.css',
                'preload',
                'style',
            ));
            $this->earlyHintCacheService->store(50, $languageUid, $collector);
        }

        foreach (self::LANGUAGE_UIDS as $languageUid) {
            $loaded = $this->earlyHintCacheService->load(50, $languageUid);
            self::assertCount(1, $loaded);
            self::assertSame('/assets/lang-' . $languageUid . '.css', $loaded[0]->href);
        }

        self::assertSame([], $this->earlyHintCacheService->load(50, 99));
    }

    public function testEarlyHintManifestIsolatedPerPage(): void
    {
        $collectorPageA = new EarlyHintCandidateCollector();
        $collectorPageA->add(new EarlyHintCandidate('/assets/page-a.css', 'preload', 'style'));
        $this->earlyHintCacheService->store(10, 0, $collectorPageA);

        $collectorPageB = new EarlyHintCandidateCollector();
        $collectorPageB->add(new EarlyHintCandidate('/assets/page-b.css', 'preload', 'style'));
        $this->earlyHintCacheService->store(20, 0, $collectorPageB);

        self::assertSame('/assets/page-a.css', $this->earlyHintCacheService->load(10, 0)[0]->href);
        self::assertSame('/assets/page-b.css', $this->earlyHintCacheService->load(20, 0)[0]->href);
        self::assertSame([], $this->earlyHintCacheService->load(99, 0));
    }

    // -----------------------------------------------------------------
    // StaticFileServeMiddleware — Link headers from real cache
    // -----------------------------------------------------------------

    public function testMiddlewareAddsLinkHeadersFromRealEarlyHintCache(): void
    {
        $collector = new EarlyHintCandidateCollector();
        $collector->add(new EarlyHintCandidate('/assets/theme.css', 'preload', 'style'));
        $this->earlyHintCacheService->store(42, 0, $collector);

        $this->writeStaticHtml(42, 0, '<html><body>Cached</body></html>');

        $middleware = new StaticFileServeMiddleware(
            $this->cacheDirectory,
            $this->extensionConfiguration,
            $this->earlyHintCacheService,
        );

        $request = $this->makeGetRequest(
            'https://example.com/de/',
            $this->makePageArguments(42),
            $this->makeLanguage(0),
        );
        $handler = $this->createStub(RequestHandlerInterface::class);
        $result = $middleware->process($request, $handler);

        self::assertInstanceOf(HtmlResponse::class, $result);
        self::assertStringContainsString(
            '</assets/theme.css>; rel=preload; as=style',
            $result->getHeaderLine('Link'),
        );
    }

    public function testMiddlewareUsesLanguageSpecificManifest(): void
    {
        $collectorDe = new EarlyHintCandidateCollector();
        $collectorDe->add(new EarlyHintCandidate('/assets/de.css', 'preload', 'style'));
        $this->earlyHintCacheService->store(42, 0, $collectorDe);

        $collectorEn = new EarlyHintCandidateCollector();
        $collectorEn->add(new EarlyHintCandidate('/assets/en.css', 'preload', 'style'));
        $this->earlyHintCacheService->store(42, 1, $collectorEn);

        $this->writeStaticHtml(42, 0, '<html><body>DE</body></html>');
        $this->writeStaticHtml(42, 1, '<html><body>EN</body></html>');

        $middleware = new StaticFileServeMiddleware(
            $this->cacheDirectory,
            $this->extensionConfiguration,
            $this->earlyHintCacheService,
        );
        $handler = $this->createStub(RequestHandlerInterface::class);

        $deResult = $middleware->process(
            $this->makeGetRequest('https://example.com/de/', $this->makePageArguments(42), $this->makeLanguage(0)),
            $handler,
        );
        self::assertStringContainsString('</assets/de.css>', $deResult->getHeaderLine('Link'));

        $enResult = $middleware->process(
            $this->makeGetRequest('https://example.com/en/', $this->makePageArguments(42), $this->makeLanguage(1)),
            $handler,
        );
        self::assertStringContainsString('</assets/en.css>', $enResult->getHeaderLine('Link'));
    }

    // -----------------------------------------------------------------
    // EarlyHintsMiddleware — real cache lookup (103 headers are CLI-noop)
    // -----------------------------------------------------------------

    public function testEarlyHintsMiddlewareLoadsManifestFromRealCache(): void
    {
        $collector = new EarlyHintCandidateCollector();
        $collector->add(new EarlyHintCandidate('/assets/hint.woff2', 'preload', 'font'));
        $this->earlyHintCacheService->store(7, 0, $collector);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn($response);

        $middleware = new EarlyHintsMiddleware($this->earlyHintCacheService);
        $request = $this->makeGetRequest(
            'https://example.com/',
            $this->makePageArguments(7),
            $this->makeLanguage(0),
        );

        $result = $middleware->process($request, $handler);

        self::assertSame($response, $result);
        self::assertCount(1, $this->earlyHintCacheService->load(7, 0));
        self::assertSame('/assets/hint.woff2', $this->earlyHintCacheService->load(7, 0)[0]->href);
    }

    public function testEarlyHintsMiddlewareSkipsWhenRealCacheHasNoManifest(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn($response);

        $middleware = new EarlyHintsMiddleware($this->earlyHintCacheService);
        $request = $this->makeGetRequest(
            'https://example.com/',
            $this->makePageArguments(7),
            $this->makeLanguage(0),
        );

        $result = $middleware->process($request, $handler);

        self::assertSame($response, $result);
        self::assertSame([], $this->earlyHintCacheService->load(7, 0));
    }

    // -----------------------------------------------------------------
    // StaticHtmlWriterService — filesystem I/O and compression
    // -----------------------------------------------------------------

    public function testHtmlWriterWriteByPageIdCreatesReadableFile(): void
    {
        $writer = new StaticHtmlWriterService($this->cacheDirectory, $this->extensionConfiguration);
        $html = '<html><body>Integration write</body></html>';

        self::assertTrue($writer->writeByPageId($html, 15, 0));

        $filePath = $this->indexPath(15, 0);
        self::assertFileExists($filePath);
        self::assertSame($html, file_get_contents($filePath));
    }

    public function testHtmlWriterIsolatesOutputPerLanguage(): void
    {
        $writer = new StaticHtmlWriterService($this->cacheDirectory, $this->extensionConfiguration);

        self::assertTrue($writer->writeByPageId('<html>DE</html>', 15, 0));
        self::assertTrue($writer->writeByPageId('<html>EN</html>', 15, 1));

        self::assertSame('<html>DE</html>', file_get_contents($this->indexPath(15, 0)));
        self::assertSame('<html>EN</html>', file_get_contents($this->indexPath(15, 1)));
    }

    public function testHtmlWriterCreatesCompressedVariantsWhenEnabled(): void
    {
        $writer = new StaticHtmlWriterService($this->cacheDirectory, $this->extensionConfiguration);
        $html = '<html><body>Compress me</body></html>';

        self::assertTrue($writer->writeByPageId($html, 20, 0));

        $basePath = $this->indexPath(20, 0);
        self::assertFileExists($basePath . '.gz');
        self::assertSame($html, gzdecode((string)file_get_contents($basePath . '.gz')));

        if (function_exists('brotli_compress')) {
            self::assertFileExists($basePath . '.br');
        }
    }

    public function testHtmlWriterRejectsIntScriptMarker(): void
    {
        $writer = new StaticHtmlWriterService($this->cacheDirectory, $this->extensionConfiguration);
        $html = '<html><!--INT_SCRIPT.-->dynamic</html>';

        self::assertFalse($writer->writeByPageId($html, 15, 0));
        self::assertFileDoesNotExist($this->indexPath(15, 0));
    }

    public function testHtmlWriterRejectsUriWithQueryString(): void
    {
        $writer = new StaticHtmlWriterService($this->cacheDirectory, $this->extensionConfiguration);

        self::assertFalse($writer->write('<html>no</html>', 'https://example.com/page/?foo=1'));
    }

    // -----------------------------------------------------------------
    // StaticFileRemovalService + AboveFoldCacheService — bucket reset
    // -----------------------------------------------------------------

    public function testClearCriticalUidsClearsAboveFoldCacheEntries(): void
    {
        $noopRemoval = $this->createMock(StaticFileRemovalService::class);
        $aboveFoldService = $this->createAboveFoldCacheService($noopRemoval);
        $pageUid = 88;

        $aboveFoldService->updateCriticalUids($pageUid, 'mobile', [1, 2, 3]);
        self::assertSame(['mobile'], $aboveFoldService->getBucketNames($pageUid));

        $noopRemoval->expects(self::once())->method('purgeByPageUid')->with($pageUid);

        $aboveFoldService->clearCriticalUids($pageUid);

        self::assertSame([], $aboveFoldService->getBucketNames($pageUid));
        self::assertSame([], $aboveFoldService->getCriticalUids($pageUid, 'mobile'));
    }

    public function testBucketResetPurgesPageIdStaticDirectoriesForAllLanguages(): void
    {
        $writer = new StaticHtmlWriterService($this->cacheDirectory, $this->extensionConfiguration);
        $pageUid = 88;
        $site = $this->createSiteWithLanguages(self::LANGUAGE_UIDS);

        foreach (self::LANGUAGE_UIDS as $languageUid) {
            self::assertTrue($writer->writeByPageId(
                '<html>lang-' . $languageUid . '</html>',
                $pageUid,
                $languageUid,
            ));
            self::assertFileExists($this->indexPath($pageUid, $languageUid));
        }

        $removalService = new StaticFileRemovalService($this->cacheDirectory, new \ReflectionClass(SiteFinder::class)->newInstanceWithoutConstructor());
        $removalService->purgeByPageUid($pageUid, $site);

        foreach (self::LANGUAGE_UIDS as $languageUid) {
            self::assertFileDoesNotExist($this->indexPath($pageUid, $languageUid));
        }
    }

    public function testPurgeForLanguageRemovesOnlyTargetLanguage(): void
    {
        $writer = new StaticHtmlWriterService($this->cacheDirectory, $this->extensionConfiguration);
        $pageUid = 90;

        self::assertTrue($writer->writeByPageId('<html>DE</html>', $pageUid, 0));
        self::assertTrue($writer->writeByPageId('<html>EN</html>', $pageUid, 1));

        $removalService = new StaticFileRemovalService($this->cacheDirectory, new \ReflectionClass(SiteFinder::class)->newInstanceWithoutConstructor());
        $removalService->purgeForLanguage($pageUid, 0);

        self::assertFileDoesNotExist($this->indexPath($pageUid, 0));
        self::assertFileExists($this->indexPath($pageUid, 1));
    }

    public function testPurgeByPageUidRemovesAllLanguageDirectories(): void
    {
        $writer = new StaticHtmlWriterService($this->cacheDirectory, $this->extensionConfiguration);
        $pageUid = 91;

        $site = $this->createSiteWithLanguages(self::LANGUAGE_UIDS);
        foreach (self::LANGUAGE_UIDS as $languageUid) {
            self::assertTrue($writer->writeByPageId('<html>L' . $languageUid . '</html>', $pageUid, $languageUid));
        }

        $removalService = new StaticFileRemovalService($this->cacheDirectory, new \ReflectionClass(SiteFinder::class)->newInstanceWithoutConstructor());
        $removalService->purgeByPageUid($pageUid, $site);

        foreach (self::LANGUAGE_UIDS as $languageUid) {
            self::assertFileDoesNotExist($this->indexPath($pageUid, $languageUid));
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function createExtensionConfiguration(
        bool $enableStaticFileCache,
        bool $enableCompression = false,
        bool $enableBrotli = false,
    ): ExtensionConfiguration {
        $config = new \ReflectionClass(ExtensionConfiguration::class)
            ->newInstanceWithoutConstructor();

        new \ReflectionProperty(ExtensionConfiguration::class, 'enableStaticFileCache')
            ->setValue($config, $enableStaticFileCache);
        new \ReflectionProperty(ExtensionConfiguration::class, 'staticFileCacheDir')
            ->setValue($config, '');
        new \ReflectionProperty(ExtensionConfiguration::class, 'enableCompression')
            ->setValue($config, $enableCompression);
        new \ReflectionProperty(ExtensionConfiguration::class, 'compressionLevel')
            ->setValue($config, 6);
        new \ReflectionProperty(ExtensionConfiguration::class, 'enableBrotli')
            ->setValue($config, $enableBrotli);
        new \ReflectionProperty(ExtensionConfiguration::class, 'debugHeaders')
            ->setValue($config, false);

        return $config;
    }

    private function createAboveFoldCacheService(StaticFileRemovalService $removalService): AboveFoldCacheService
    {
        $aboveFoldBackend = new TransientMemoryBackend([]);
        $aboveFoldFrontend = new VariableFrontend('mai_assets_above_fold', $aboveFoldBackend);

        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager
            ->method('getCache')
            ->with('mai_assets_above_fold')
            ->willReturn($aboveFoldFrontend);

        return new AboveFoldCacheService(
            $cacheManager,
            $this->createStub(EventDispatcherInterface::class),
            $removalService,
        );
    }

    private function writeStaticHtml(int $pageUid, int $languageUid, string $html): void
    {
        $writer = new StaticHtmlWriterService(
            $this->cacheDirectory,
            $this->createExtensionConfiguration(enableStaticFileCache: true),
        );
        self::assertTrue($writer->writeByPageId($html, $pageUid, $languageUid));
    }

    private function indexPath(int $pageUid, int $languageUid): string
    {
        $pageDir = $this->cacheDirectory->getPageDirectoryById($pageUid, $languageUid);

        return rtrim($pageDir, '/') . '/' . StaticHtmlWriterService::INDEX_FILENAME;
    }

    /**
     * @param list<int> $languageIds
     */
    private function createSiteWithLanguages(array $languageIds): Site
    {
        $site = $this->createMock(Site::class);
        $languages = [];
        foreach ($languageIds as $langId) {
            $language = $this->createMock(SiteLanguage::class);
            $language->method('getLanguageId')->willReturn($langId);
            $languages[] = $language;
        }
        $site->method('getLanguages')->willReturn($languages);

        return $site;
    }

    private function makeGetRequest(
        string $uri,
        ?PageArguments $pageArguments = null,
        ?SiteLanguage $language = null,
    ): ServerRequestInterface {
        $uriMock = $this->createMock(UriInterface::class);
        $uriMock->method('__toString')->willReturn($uri);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn($uriMock);
        $request->method('getHeader')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name): mixed => match ($name) {
                'routing' => $pageArguments,
                'language' => $language,
                default => null,
            }
        );

        return $request;
    }

    private function makePageArguments(int $pageId): PageArguments
    {
        $pageArguments = $this->createMock(PageArguments::class);
        $pageArguments->method('getPageId')->willReturn($pageId);

        return $pageArguments;
    }

    private function makeLanguage(int $languageId): SiteLanguage
    {
        $language = $this->createMock(SiteLanguage::class);
        $language->method('getLanguageId')->willReturn($languageId);

        return $language;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $path = $item->getPathname();
            $item->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
