<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Middleware;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\Middleware\StaticFileServeMiddleware;
use Maispace\MaiAssets\StaticFileCache\StaticFileCacheDirectory;
use Maispace\MaiAssets\StaticFileCache\StaticHtmlWriterService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class StaticFileServeMiddlewareTest extends TestCase
{
    private string $tempBaseDir;
    private StaticFileCacheDirectory $cacheDirectory;
    private RequestHandlerInterface&\PHPUnit\Framework\MockObject\MockObject $handler;
    private ResponseInterface&\PHPUnit\Framework\MockObject\MockObject $fallbackResponse;
    private FrontendInterface&\PHPUnit\Framework\MockObject\MockObject $cacheFrontend;
    private EarlyHintCacheService $earlyHintCacheService;

    protected function setUp(): void
    {
        $this->tempBaseDir = sys_get_temp_dir() . '/mai_assets_sfm_test_' . uniqid('', true);
        mkdir($this->tempBaseDir, 0777, true);

        Environment::initialize(
            new ApplicationContext('Production'),
            true,
            true,
            $this->tempBaseDir,
            $this->tempBaseDir,
            $this->tempBaseDir . '/var',
            $this->tempBaseDir . '/config',
            $this->tempBaseDir . '/index.php',
            'UNIX',
        );

        $this->cacheDirectory = $this->makeCacheDirectory();
        $this->fallbackResponse = $this->createMock(ResponseInterface::class);
        $this->handler = $this->createMock(RequestHandlerInterface::class);
        $this->handler->method('handle')->willReturn($this->fallbackResponse);

        // Build EarlyHintCacheService instance without constructor.
        // Note: do NOT set a default willReturn on cacheFrontend->get() here.
        // Individual test methods configure the return value via expects() or
        // set up their own method() stub. The unstubbed default (null) causes
        // load() to return [] just like a cache miss.
        $this->cacheFrontend = $this->createMock(FrontendInterface::class);

        $this->earlyHintCacheService = (new \ReflectionClass(EarlyHintCacheService::class))
            ->newInstanceWithoutConstructor();
        $cacheProp = new \ReflectionProperty(EarlyHintCacheService::class, 'cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->earlyHintCacheService, $this->cacheFrontend);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempBaseDir);
    }

    // -----------------------------------------------------------------
    // Hit: static file exists → serves it with 200
    // -----------------------------------------------------------------

    public function testServesStaticFileWhenIndexHtmlExists(): void
    {
        $this->writeStaticFile('https://example.com/de/', '<html><body>Hello</body></html>');

        $middleware = $this->makeMiddleware();
        $request = $this->makeGetRequest('https://example.com/de/', pageArguments: $this->makePageArguments(1));
        $result = $middleware->process($request, $this->handler);

        self::assertInstanceOf(HtmlResponse::class, $result);
        self::assertSame(200, $result->getStatusCode());
        self::assertSame('<html><body>Hello</body></html>', (string)$result->getBody());
        self::assertSame('text/html; charset=utf-8', $result->getHeaderLine('Content-Type'));
    }

    public function testServesStaticFileForRootPath(): void
    {
        $this->writeStaticFile('https://example.com/', '<html><body>Root</body></html>');

        $middleware = $this->makeMiddleware();
        $request = $this->makeGetRequest('https://example.com/', pageArguments: $this->makePageArguments(1));
        $result = $middleware->process($request, $this->handler);

        self::assertInstanceOf(HtmlResponse::class, $result);
        self::assertSame('<html><body>Root</body></html>', (string)$result->getBody());
    }

    public function testServesStaticFileWithNestedPath(): void
    {
        $this->writeStaticFile('https://example.com/de/news/article/', '<html><body>Nested</body></html>');

        $middleware = $this->makeMiddleware();
        $request = $this->makeGetRequest('https://example.com/de/news/article/', pageArguments: $this->makePageArguments(1));
        $result = $middleware->process($request, $this->handler);

        self::assertInstanceOf(HtmlResponse::class, $result);
        self::assertSame('<html><body>Nested</body></html>', (string)$result->getBody());
    }

    // -----------------------------------------------------------------
    // Content-Encoding negotiation
    // -----------------------------------------------------------------

    public function testServesBrotliVariantWhenClientPrefersBrotli(): void
    {
        $this->writeStaticFile('https://example.com/page/', 'plain');
        $this->writeCompressedFile('https://example.com/page/', 'br', 'brotli-content');

        $middleware = $this->makeMiddleware();
        $request = $this->makeGetRequest('https://example.com/page/', ['accept-encoding' => ['br, gzip']]);
        $result = $middleware->process($request, $this->handler);

        self::assertInstanceOf(HtmlResponse::class, $result);
        self::assertSame('brotli-content', (string)$result->getBody());
        self::assertSame('br', $result->getHeaderLine('Content-Encoding'));
    }

    public function testServesGzipVariantWhenClientPrefersGzip(): void
    {
        $this->writeStaticFile('https://example.com/page/', 'plain');
        $this->writeCompressedFile('https://example.com/page/', 'gz', 'gzip-content');

        $middleware = $this->makeMiddleware();
        $request = $this->makeGetRequest('https://example.com/page/', ['accept-encoding' => ['gzip']]);
        $result = $middleware->process($request, $this->handler);

        self::assertInstanceOf(HtmlResponse::class, $result);
        self::assertSame('gzip-content', (string)$result->getBody());
        self::assertSame('gzip', $result->getHeaderLine('Content-Encoding'));
    }

    public function testPrefersBrotliWhenBothVariantsAvailable(): void
    {
        $this->writeStaticFile('https://example.com/page/', 'plain');
        $this->writeCompressedFile('https://example.com/page/', 'br', 'br-win');
        $this->writeCompressedFile('https://example.com/page/', 'gz', 'gz-lose');

        $middleware = $this->makeMiddleware();
        $request = $this->makeGetRequest('https://example.com/page/', ['accept-encoding' => ['gzip, br']]);
        $result = $middleware->process($request, $this->handler);

        self::assertSame('br-win', (string)$result->getBody());
    }

    public function testServesUncompressedWhenNoEncodingAdvertised(): void
    {
        $this->writeStaticFile('https://example.com/page/', 'plain');
        $this->writeCompressedFile('https://example.com/page/', 'br', 'br-content');

        $middleware = $this->makeMiddleware();
        $request = $this->makeGetRequest('https://example.com/page/');
        $result = $middleware->process($request, $this->handler);

        self::assertSame('plain', (string)$result->getBody());
    }

    // -----------------------------------------------------------------
    // Miss: no static file → falls through to handler
    // -----------------------------------------------------------------

    public function testFallsThroughWhenIndexHtmlDoesNotExist(): void
    {
        $this->handler->expects(self::once())->method('handle')->willReturn($this->fallbackResponse);

        $middleware = $this->makeMiddleware();
        $request = $this->makeGetRequest('https://example.com/nonexistent/');
        $result = $middleware->process($request, $this->handler);

        self::assertSame($this->fallbackResponse, $result);
    }

    // -----------------------------------------------------------------
    // Disabled: enableStaticFileCache = false → falls through
    // -----------------------------------------------------------------

    public function testFallsThroughWhenStaticFileCacheIsDisabled(): void
    {
        $this->writeStaticFile('https://example.com/de/', '<html><body>Exists</body></html>');
        $this->handler->expects(self::once())->method('handle')->willReturn($this->fallbackResponse);

        $middleware = $this->makeMiddleware(enableStaticFileCache: false);
        $request = $this->makeGetRequest('https://example.com/de/');
        $result = $middleware->process($request, $this->handler);

        self::assertSame($this->fallbackResponse, $result);
    }

    // -----------------------------------------------------------------
    // Non-cacheable methods → falls through
    // -----------------------------------------------------------------

    #[DataProvider('provideNonCacheableMethods')]
    public function testFallsThroughForNonGetRequests(string $method): void
    {
        $this->writeStaticFile('https://example.com/de/', '<html><body>Exists</body></html>');
        $this->handler->expects(self::once())->method('handle')->willReturn($this->fallbackResponse);

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest($method, 'https://example.com/de/');
        $result = $middleware->process($request, $this->handler);

        self::assertSame($this->fallbackResponse, $result);
    }

    public static function provideNonCacheableMethods(): \Generator
    {
        yield ['POST'];
        yield ['PUT'];
        yield ['DELETE'];
        yield ['HEAD'];
        yield ['PATCH'];
        yield ['OPTIONS'];
    }

    // -----------------------------------------------------------------
    // Query string → falls through
    // -----------------------------------------------------------------

    public function testFallsThroughWhenUriHasQueryString(): void
    {
        $this->writeStaticFile('https://example.com/de/', '<html><body>Exists</body></html>');
        $this->handler->expects(self::once())->method('handle')->willReturn($this->fallbackResponse);

        $middleware = $this->makeMiddleware();
        $request = $this->makeGetRequest('https://example.com/de/?lang=en');
        $result = $middleware->process($request, $this->handler);

        self::assertSame($this->fallbackResponse, $result);
    }

    // -----------------------------------------------------------------
    // Invalid URI → falls through
    // -----------------------------------------------------------------

    public function testFallsThroughForInvalidUri(): void
    {
        $this->handler->expects(self::once())->method('handle')->willReturn($this->fallbackResponse);

        $middleware = $this->makeMiddleware();
        $request = $this->makeGetRequest('not-a-valid-url');
        $result = $middleware->process($request, $this->handler);

        self::assertSame($this->fallbackResponse, $result);
    }

    // -----------------------------------------------------------------
    // Debug headers
    // -----------------------------------------------------------------

    public function testAddsStaticCacheHeaderWhenDebugHeadersEnabled(): void
    {
        $this->writeStaticFile('https://example.com/de/', '<html><body>Hello</body></html>');

        $middleware = $this->makeMiddleware(enableStaticFileCache: true, debugHeaders: true);
        $request = $this->makeGetRequest('https://example.com/de/', pageArguments: $this->makePageArguments(1));
        $result = $middleware->process($request, $this->handler);

        self::assertInstanceOf(HtmlResponse::class, $result);
        self::assertSame('1', $result->getHeaderLine('X-Mai-Static-Cache'));
    }

    public function testDoesNotAddStaticCacheHeaderWhenDebugHeadersDisabled(): void
    {
        $this->writeStaticFile('https://example.com/de/', '<html><body>Hello</body></html>');

        $middleware = $this->makeMiddleware(enableStaticFileCache: true, debugHeaders: false);
        $request = $this->makeGetRequest('https://example.com/de/', pageArguments: $this->makePageArguments(1));
        $result = $middleware->process($request, $this->handler);

        self::assertInstanceOf(HtmlResponse::class, $result);
        self::assertSame('', $result->getHeaderLine('X-Mai-Static-Cache'));
    }

    // -----------------------------------------------------------------
    // Hybrid: early hints Link headers on static HTML response
    // -----------------------------------------------------------------

    public function testAddsEarlyHintLinkHeadersWhenManifestExists(): void
    {
        $this->writeStaticFile('https://example.com/de/', '<html><body>Hello</body></html>');
        $this->cacheFrontend->method('get')->willReturn([
            ['href' => '/assets/style.css', 'rel' => 'preload', 'as' => 'style', 'type' => '', 'crossorigin' => ''],
            ['href' => '/assets/script.js', 'rel' => 'modulepreload', 'as' => 'script', 'type' => '', 'crossorigin' => ''],
        ]);

        $middleware = $this->makeMiddleware();
        $request = $this->makeGetRequest(
            'https://example.com/de/',
            pageArguments: $this->makePageArguments(42),
            language: $this->makeLanguage(0),
        );
        $result = $middleware->process($request, $this->handler);

        self::assertInstanceOf(HtmlResponse::class, $result);
        $linkHeader = $result->getHeaderLine('Link');
        self::assertStringContainsString('</assets/style.css>; rel=preload; as=style', $linkHeader);
        self::assertStringContainsString('</assets/script.js>; rel=modulepreload; as=script', $linkHeader);
    }

    public function testDoesNotAddLinkHeadersWhenManifestIsEmpty(): void
    {
        $this->writeStaticFile('https://example.com/de/', '<html><body>Hello</body></html>');

        $middleware = $this->makeMiddleware();
        $request = $this->makeGetRequest(
            'https://example.com/de/',
            pageArguments: $this->makePageArguments(42),
            language: $this->makeLanguage(0),
        );
        $result = $middleware->process($request, $this->handler);

        self::assertInstanceOf(HtmlResponse::class, $result);
        self::assertSame('', $result->getHeaderLine('Link'));
    }

    public function testDoesNotAddLinkHeadersWhenRoutingAttributeIsNull(): void
    {
        $this->writeStaticFile('https://example.com/de/', '<html><body>Hello</body></html>');
        $this->cacheFrontend->method('get')->willReturn([
            ['href' => '/assets/style.css', 'rel' => 'preload', 'as' => 'style', 'type' => '', 'crossorigin' => ''],
        ]);

        $middleware = $this->makeMiddleware();
        $request = $this->makeGetRequest('https://example.com/de/');
        $result = $middleware->process($request, $this->handler);

        self::assertInstanceOf(HtmlResponse::class, $result);
        self::assertSame('', $result->getHeaderLine('Link'));
    }

    public function testUsesCorrectCacheKeyForPageAndLanguage(): void
    {
        $this->writeStaticFile('https://example.com/de/', '<html><body>Hello</body></html>');
        $this->cacheFrontend
            ->expects(self::once())
            ->method('get')
            ->with('earlyhints_42_3')
            ->willReturn(false);

        $middleware = $this->makeMiddleware();
        $request = $this->makeGetRequest(
            'https://example.com/de/',
            pageArguments: $this->makePageArguments(42),
            language: $this->makeLanguage(3),
        );
        $result = $middleware->process($request, $this->handler);

        self::assertInstanceOf(HtmlResponse::class, $result);
    }

    public function testIncludesDebugHeaderAlongsideEarlyHintLinks(): void
    {
        $this->writeStaticFile('https://example.com/de/', '<html><body>Hello</body></html>');
        $this->cacheFrontend->method('get')->willReturn([
            ['href' => '/assets/style.css', 'rel' => 'preload', 'as' => 'style', 'type' => '', 'crossorigin' => ''],
        ]);

        $middleware = $this->makeMiddleware(enableStaticFileCache: true, debugHeaders: true);
        $request = $this->makeGetRequest(
            'https://example.com/de/',
            pageArguments: $this->makePageArguments(42),
            language: $this->makeLanguage(0),
        );
        $result = $middleware->process($request, $this->handler);

        self::assertInstanceOf(HtmlResponse::class, $result);
        self::assertSame('1', $result->getHeaderLine('X-Mai-Static-Cache'));
        self::assertStringContainsString('</assets/style.css>; rel=preload; as=style', $result->getHeaderLine('Link'));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function makeMiddleware(
        bool $enableStaticFileCache = true,
        bool $debugHeaders = false,
    ): StaticFileServeMiddleware {
        $config = (new \ReflectionClass(ExtensionConfiguration::class))
            ->newInstanceWithoutConstructor();

        (new \ReflectionProperty(ExtensionConfiguration::class, 'enableStaticFileCache'))
            ->setValue($config, $enableStaticFileCache);
        (new \ReflectionProperty(ExtensionConfiguration::class, 'staticFileCacheDir'))
            ->setValue($config, '');
        (new \ReflectionProperty(ExtensionConfiguration::class, 'debugHeaders'))
            ->setValue($config, $debugHeaders);

        return new StaticFileServeMiddleware($this->cacheDirectory, $config, $this->earlyHintCacheService);
    }

    private function makeCacheDirectory(): StaticFileCacheDirectory
    {
        $config = (new \ReflectionClass(ExtensionConfiguration::class))
            ->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ExtensionConfiguration::class, 'staticFileCacheDir'))
            ->setValue($config, '');

        return new StaticFileCacheDirectory($config);
    }

    /**
     * Resolve the absolute filesystem path where the index.html for the
     * given request URI would live.
     */
    private function filePathForUri(string $requestUri): string
    {
        $pageDir = $this->cacheDirectory->getPageDirectory($requestUri);

        return rtrim($pageDir, '/') . '/' . StaticHtmlWriterService::INDEX_FILENAME;
    }

    /**
     * Create the directory tree and write index.html for the given URI.
     */
    private function writeStaticFile(string $requestUri, string $content): void
    {
        $filePath = $this->filePathForUri($requestUri);
        $dir = \dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($filePath, $content);
    }

    /**
     * Write a compressed variant alongside the index.html for the given URI.
     */
    private function writeCompressedFile(string $requestUri, string $extension, string $content): void
    {
        $filePath = $this->filePathForUri($requestUri) . '.' . $extension;
        file_put_contents($filePath, $content);
    }

    private function makeGetRequest(
        string $uri,
        array $extraHeaders = [],
        ?PageArguments $pageArguments = null,
        ?SiteLanguage $language = null,
    ): ServerRequestInterface {
        return $this->makeRequest('GET', $uri, $extraHeaders, $pageArguments, $language);
    }

    private function makeRequest(
        string $method,
        string $uri,
        array $extraHeaders = [],
        ?PageArguments $pageArguments = null,
        ?SiteLanguage $language = null,
    ): ServerRequestInterface {
        $uriMock = $this->createMock(UriInterface::class);
        $uriMock->method('__toString')->willReturn($uri);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uriMock);

        $headers = array_merge(['accept-encoding' => []], $extraHeaders);
        $request->method('getHeader')->willReturnCallback(
            static function (string $name) use ($headers): array {
                return $headers[$name] ?? [];
            }
        );

        $request->method('getAttribute')->willReturnCallback(
            static function (string $name) use ($pageArguments, $language): mixed {
                return match ($name) {
                    'routing'  => $pageArguments,
                    'language' => $language,
                    default    => null,
                };
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
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $path = $item->getPathname();
            $item->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
