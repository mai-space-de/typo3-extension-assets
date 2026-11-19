<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Middleware;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Middleware\StaticFileWriterMiddleware;
use Maispace\MaiAssets\Service\PageOptimizationReadinessService;
use Maispace\MaiAssets\StaticFileCache\StaticCacheRequestGuard;
use Maispace\MaiAssets\StaticFileCache\StaticFileCacheDirectory;
use Maispace\MaiAssets\StaticFileCache\StaticFileIndex;
use Maispace\MaiAssets\StaticFileCache\StaticHtmlWriterService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Cache\CacheEntry;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce;
use TYPO3\CMS\Frontend\Page\PageParts;

final class StaticFileWriterMiddlewareTest extends TestCase
{
    private const string HTML = '<!DOCTYPE html><html><body>page</body></html>';

    private string $root;
    private StaticHtmlWriterService $writer;
    private StaticFileIndex $index;
    private VariableFrontend $indexCache;
    private ExtensionConfiguration $configuration;
    /** @var list<string> buckets reported for every page */
    private array $buckets = ['mobile', 'tablet', 'desktop'];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mai_assets_writer_' . uniqid('', true);
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
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = ['enableCompression' => false, 'enableStaticFileCache' => true];
        $this->configuration = new ExtensionConfiguration((new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor());
        $this->writer = new StaticHtmlWriterService(new StaticFileCacheDirectory($this->configuration), $this->configuration);

        $this->indexCache = new VariableFrontend(StaticFileIndex::CACHE_IDENTIFIER, new TransientMemoryBackend());
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($this->indexCache);
        $this->index = new StaticFileIndex($cacheManager);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets']);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testFreshRenderingIsWrittenWithTheCompletePageCacheMetadata(): void
    {
        // pageId_5, the page's cache_tags and the starttime/endtime lifetime are only known
        // after AfterCacheableContentIsGeneratedEvent.
        $collector = $this->collector(pageCacheWritten: true);
        $collector->addCacheTags(new CacheTag('pageId_5', 600), new CacheTag('tx_news_pid_3'));
        $nonce = new ConsumableNonce();

        $this->process($this->request(collector: $collector, nonce: $nonce), new HtmlResponse(self::HTML, 200, ['X-Frame-Options' => 'DENY']));

        self::assertSame(self::HTML, $this->storedHtml(5, 0));
        self::assertTrue($this->index->isValid(5, 0));
        $this->indexCache->flushByTag('tx_news_pid_3');
        self::assertFalse($this->index->isValid(5, 0));

        $metadata = $this->writer->readMetadata(5, 0, '');
        self::assertSame(['X-Frame-Options' => ['DENY']], $metadata['headers']);
        self::assertSame($nonce->value, $metadata['nonce']);
        self::assertEqualsWithDelta(time() + 600, $metadata['expires'], 2);
    }

    public function testPageCacheHitFillsAMissingFile(): void
    {
        // e.g. the page was rendered for a logged-in editor first.
        $this->process($this->request(pageParts: $this->pageParts(fromCache: true)), new HtmlResponse(self::HTML));

        self::assertSame(self::HTML, $this->storedHtml(5, 0));
        self::assertTrue($this->index->isValid(5, 0));
    }

    public function testPageCacheHitKeepsAValidFile(): void
    {
        $this->writer->writeByPageId('<!DOCTYPE html><html>existing</html>', 5, 0);
        $this->index->register(5, 0, [], 3600);

        $this->process($this->request(pageParts: $this->pageParts(fromCache: true)), new HtmlResponse(self::HTML));

        self::assertSame('<!DOCTYPE html><html>existing</html>', $this->storedHtml(5, 0));
    }

    public function testNewsDetailPageIsWrittenAsItsOwnVariant(): void
    {
        $routing = new PageArguments(5, '0', ['tx_news_pi1' => ['news' => 7]]);

        $this->process($this->request(routing: $routing), new HtmlResponse(self::HTML));

        $variant = (string) StaticCacheRequestGuard::variantOf($routing);
        self::assertSame(self::HTML, $this->storedHtml(5, 0, $variant));
        self::assertNull($this->storedHtml(5, 0));
    }

    public function testNothingIsWrittenWhenTheBodyIsNotShareable(): void
    {
        $cases = [
            'non-cached parts' => [$this->request(pageParts: $this->pageParts(withIntObjects: true)), new HtmlResponse(self::HTML)],
            'page cache not written (no_cache)' => [$this->request(collector: $this->collector(pageCacheWritten: false)), new HtmlResponse(self::HTML)],
            'error response' => [$this->request(), new HtmlResponse(self::HTML, 500)],
            'query string' => [$this->request(uri: 'https://example.test/page?x=1'), new HtmlResponse(self::HTML)],
            'not html' => [$this->request(), new HtmlResponse('<?xml version="1.0"?><urlset/>', 200, ['Content-Type' => 'application/xml'])],
        ];

        foreach ($cases as $name => [$request, $response]) {
            $this->process($request, $response);
            self::assertNull($this->storedHtml(5, 0), $name);
        }
    }

    public function testNothingIsWrittenBeforeAllViewportsReported(): void
    {
        $this->buckets = ['mobile'];

        $this->process($this->request(), new HtmlResponse(self::HTML));

        self::assertNull($this->storedHtml(5, 0));
    }

    public function testRewritingTheHtmlDropsOutdatedMetadata(): void
    {
        // A new rendering has a new nonce; old metadata must never be combined with it.
        $this->writer->writeByPageId('<!DOCTYPE html><html>v1</html>', 5, 0);
        $this->writer->writeMetadata(5, 0, '', ['headers' => [], 'nonce' => 'OLD', 'expires' => 1]);

        $this->writer->writeByPageId('<!DOCTYPE html><html>v2</html>', 5, 0);

        self::assertSame('', $this->writer->readMetadata(5, 0, '')['nonce']);
    }

    private function process(ServerRequestInterface $request, HtmlResponse $response): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $guard = $this->createMock(StaticCacheRequestGuard::class);
        $guard->method('isShareable')->willReturn(true);

        $aboveFold = (new \ReflectionClass(AboveFoldCacheService::class))->newInstanceWithoutConstructor();
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturnCallback(fn(string $key): mixed => str_starts_with($key, 'buckets_') ? $this->buckets : false);
        (new \ReflectionProperty(AboveFoldCacheService::class, 'cache'))->setValue($aboveFold, $cache);

        (new StaticFileWriterMiddleware(
            $this->writer,
            $this->configuration,
            $guard,
            new PageOptimizationReadinessService($aboveFold, $this->configuration),
            $this->index,
        ))->process($request, $handler);
    }

    private function request(
        ?PageArguments $routing = null,
        ?PageParts $pageParts = null,
        ?CacheDataCollector $collector = null,
        ?ConsumableNonce $nonce = null,
        string $uri = 'https://example.test/page',
    ): ServerRequestInterface {
        return (new ServerRequest($uri, 'GET'))
            ->withAttribute('routing', $routing ?? new PageArguments(5, '0', []))
            ->withAttribute('frontend.page.parts', $pageParts ?? $this->pageParts())
            ->withAttribute('frontend.cache.collector', $collector ?? $this->collector(pageCacheWritten: true))
            ->withAttribute('nonce', $nonce);
    }

    private function pageParts(bool $fromCache = false, bool $withIntObjects = false): PageParts
    {
        $pageParts = new PageParts();
        if ($fromCache) {
            $pageParts->setPageContentWasLoadedFromCache();
        }
        if ($withIntObjects) {
            $pageParts->addNotCachedContentElement(['substKey' => 'INT_SCRIPT.x', 'target' => 'x', 'permanent' => false]);
        }

        return $pageParts;
    }

    private function collector(bool $pageCacheWritten): CacheDataCollector
    {
        $collector = new CacheDataCollector();
        if ($pageCacheWritten) {
            $collector->enqueueCacheEntry(new CacheEntry('tsfe-page-cache', [], static function (): void {}));
        }

        return $collector;
    }

    private function storedHtml(int $page, int $language, string $variant = ''): ?string
    {
        $file = $this->root . '/public/typo3temp/assets/mai_assets_static/' . $page . '_' . $language . '/'
            . ($variant !== '' ? 'v/' . $variant . '/' : '') . 'index.html';

        return is_file($file) ? (string) file_get_contents($file) : null;
    }
}
