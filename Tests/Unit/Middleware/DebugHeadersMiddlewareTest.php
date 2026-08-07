<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Middleware;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\Middleware\DebugHeadersMiddleware;
use Maispace\MaiAssets\Service\PageOptimizationReadinessService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final class DebugHeadersMiddlewareTest extends TestCase
{
    private ResponseInterface&MockObject $response;
    private RequestHandlerInterface&MockObject $handler;
    private FrontendInterface&MockObject $aboveFoldFrontend;
    private FrontendInterface&MockObject $earlyHintFrontend;
    private EarlyHintCacheService $earlyHintCacheService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->response = $this->createMock(ResponseInterface::class);
        $this->handler = $this->createMock(RequestHandlerInterface::class);
        $this->handler->method('handle')->willReturn($this->response);

        $this->aboveFoldFrontend = $this->createMock(FrontendInterface::class);

        $cacheManager = $this->createMock(CacheManager::class);
        $this->earlyHintFrontend = $this->createMock(FrontendInterface::class);
        $cacheManager
            ->method('getCache')
            ->with('mai_assets_early_hints')
            ->willReturn($this->earlyHintFrontend);
        $this->earlyHintCacheService = new EarlyHintCacheService($cacheManager);
    }

    private function makeConfig(bool $debugHeaders): ExtensionConfiguration
    {
        $config = new \ReflectionClass(ExtensionConfiguration::class)
            ->newInstanceWithoutConstructor();
        new \ReflectionProperty(ExtensionConfiguration::class, 'debugHeaders')
            ->setValue($config, $debugHeaders);
        new \ReflectionProperty(ExtensionConfiguration::class, 'viewportBuckets')
            ->setValue($config, [
                'mobile' => 768,
                'tablet' => 1024,
                'desktop' => PHP_INT_MAX,
            ]);

        return $config;
    }

    private function makeAboveFoldCacheService(): AboveFoldCacheService
    {
        $service = new \ReflectionClass(AboveFoldCacheService::class)
            ->newInstanceWithoutConstructor();
        $cacheProp = new \ReflectionProperty(AboveFoldCacheService::class, 'cache');
        $cacheProp->setValue($service, $this->aboveFoldFrontend);

        return $service;
    }

    private function makeReadinessService(ExtensionConfiguration $config): PageOptimizationReadinessService
    {
        return new PageOptimizationReadinessService(
            $this->makeAboveFoldCacheService(),
            $config,
        );
    }

    // -----------------------------------------------------------------
    // No-op when debugHeaders is disabled
    // -----------------------------------------------------------------

    // -----------------------------------------------------------------
    // No-op when debugHeaders is disabled
    // -----------------------------------------------------------------

    public function testNoOpWhenDebugHeadersDisabled(): void
    {
        $this->response
            ->expects(self::never())
            ->method('withHeader');

        $config = $this->makeConfig(debugHeaders: false);
        $readiness = $this->makeReadinessService($config);

        $middleware = new DebugHeadersMiddleware($config, $readiness, $this->earlyHintCacheService);
        $request = $this->createMock(ServerRequestInterface::class);

        $result = $middleware->process($request, $this->handler);

        self::assertSame($this->response, $result);
    }

    // -----------------------------------------------------------------
    // No-op when no page information
    // -----------------------------------------------------------------

    public function testNoOpWhenNoPageInformation(): void
    {
        $this->response
            ->expects(self::never())
            ->method('withHeader');

        $config = $this->makeConfig(debugHeaders: true);
        $readiness = $this->makeReadinessService($config);

        $middleware = new DebugHeadersMiddleware($config, $readiness, $this->earlyHintCacheService);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('frontend.page.information')
            ->willReturn(null);

        $result = $middleware->process($request, $this->handler);

        self::assertSame($this->response, $result);
    }

    // -----------------------------------------------------------------
    // x-mai-static-ready: 1 when page is ready
    // -----------------------------------------------------------------

    public function testAddsReadyHeaderWhenPageIsReady(): void
    {
        $pageInformation = $this->createPageInformation(42);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(fn(string $name) => match ($name) {
                'frontend.page.information' => $pageInformation,
                'language' => null,
                default => null,
            });

        $this->response
            ->expects(self::once())
            ->method('withHeader')
            ->with('X-Mai-Static-Ready', '1')
            ->willReturn($this->response);

        // All viewport buckets reported
        $this->aboveFoldFrontend
            ->method('get')
            ->willReturnCallback(fn (string $key) => match ($key) {
                'buckets_42' => ['mobile', 'tablet', 'desktop'],
                default => false,
            });

        // Manifest exists
        $this->earlyHintFrontend
            ->method('get')
            ->willReturnCallback(fn (string $key) => match ($key) {
                'earlyhints_42_0' => [['href' => '/style.css', 'rel' => 'preload']],
                default => false,
            });

        $config = $this->makeConfig(debugHeaders: true);
        $readiness = $this->makeReadinessService($config);

        $middleware = new DebugHeadersMiddleware($config, $readiness, $this->earlyHintCacheService);

        $result = $middleware->process($request, $this->handler);

        self::assertSame($this->response, $result);
    }

    // -----------------------------------------------------------------
    // x-mai-static-ready: 0 when page is NOT ready
    // -----------------------------------------------------------------

    public function testAddsNotReadyHeaderWhenReadinessServiceSaysNotReady(): void
    {
        $pageInformation = $this->createPageInformation(42);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(fn(string $name) => match ($name) {
                'frontend.page.information' => $pageInformation,
                'language' => null,
                default => null,
            });

        $this->response
            ->expects(self::once())
            ->method('withHeader')
            ->with('X-Mai-Static-Ready', '0')
            ->willReturn($this->response);

        // Only one viewport bucket reported — not ready
        $this->aboveFoldFrontend
            ->method('get')
            ->willReturnCallback(fn (string $key) => match ($key) {
                'buckets_42' => ['mobile'],
                default => false,
            });

        $this->earlyHintFrontend
            ->expects(self::never())
            ->method('get');

        $config = $this->makeConfig(debugHeaders: true);
        $readiness = $this->makeReadinessService($config);

        $middleware = new DebugHeadersMiddleware($config, $readiness, $this->earlyHintCacheService);

        $result = $middleware->process($request, $this->handler);

        self::assertSame($this->response, $result);
    }

    public function testAddsNotReadyHeaderWhenManifestMissing(): void
    {
        $pageInformation = $this->createPageInformation(42);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(fn(string $name) => match ($name) {
                'frontend.page.information' => $pageInformation,
                'language' => null,
                default => null,
            });

        $this->response
            ->expects(self::once())
            ->method('withHeader')
            ->with('X-Mai-Static-Ready', '0')
            ->willReturn($this->response);

        // All viewport buckets reported — readiness passes
        $this->aboveFoldFrontend
            ->method('get')
            ->willReturnCallback(fn (string $key) => match ($key) {
                'buckets_42' => ['mobile', 'tablet', 'desktop'],
                default => false,
            });

        // But manifest is empty
        $this->earlyHintFrontend
            ->method('get')
            ->willReturnCallback(fn (string $key) => match ($key) {
                'earlyhints_42_0' => false,
                default => false,
            });

        $config = $this->makeConfig(debugHeaders: true);
        $readiness = $this->makeReadinessService($config);

        $middleware = new DebugHeadersMiddleware($config, $readiness, $this->earlyHintCacheService);

        $result = $middleware->process($request, $this->handler);

        self::assertSame($this->response, $result);
    }

    // -----------------------------------------------------------------
    // Language-aware
    // -----------------------------------------------------------------

    public function testUsesLanguageUidFromRequest(): void
    {
        $pageInformation = $this->createPageInformation(7);

        $language = new readonly class(1) {
            public function __construct(
                private int $languageId,
            ) {}

            public function getLanguageId(): int
            {
                return $this->languageId;
            }
        };

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(fn(string $name) => match ($name) {
                'frontend.page.information' => $pageInformation,
                'language' => $language,
                default => null,
            });

        $this->response
            ->expects(self::once())
            ->method('withHeader')
            ->with('X-Mai-Static-Ready', '1')
            ->willReturn($this->response);

        // All viewport buckets reported
        $this->aboveFoldFrontend
            ->method('get')
            ->willReturnCallback(fn (string $key) => match ($key) {
                'buckets_7' => ['mobile', 'tablet', 'desktop'],
                default => false,
            });

        // Manifest exists for language 1
        $this->earlyHintFrontend
            ->method('get')
            ->willReturnCallback(fn (string $key) => match ($key) {
                'earlyhints_7_1' => [['href' => '/style.css', 'rel' => 'preload']],
                default => false,
            });

        $config = $this->makeConfig(debugHeaders: true);
        $readiness = $this->makeReadinessService($config);

        $middleware = new DebugHeadersMiddleware($config, $readiness, $this->earlyHintCacheService);

        $result = $middleware->process($request, $this->handler);

        self::assertSame($this->response, $result);
    }

    // -----------------------------------------------------------------
    // Page UID zero → no-op
    // -----------------------------------------------------------------

    public function testNoOpWhenPageUidIsZero(): void
    {
        $pageInformation = $this->createPageInformation(0);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(fn(string $name) => match ($name) {
                'frontend.page.information' => $pageInformation,
                default => null,
            });

        $this->response
            ->expects(self::never())
            ->method('withHeader');

        $config = $this->makeConfig(debugHeaders: true);
        $readiness = $this->makeReadinessService($config);

        $middleware = new DebugHeadersMiddleware($config, $readiness, $this->earlyHintCacheService);

        $result = $middleware->process($request, $this->handler);

        self::assertSame($this->response, $result);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return object{getPageRecord(): array{uid: int}}
     */
    private function createPageInformation(int $pageUid): object
    {
        return new readonly class($pageUid) {
            public function __construct(
                private int $pageUid,
            ) {}

            /** @return array{uid: int} */
            public function getPageRecord(): array
            {
                return ['uid' => $this->pageUid];
            }
        };
    }
}
