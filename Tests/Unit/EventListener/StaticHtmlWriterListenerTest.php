<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\EventListener;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\EventListener\StaticHtmlWriterListener;
use Maispace\MaiAssets\Service\PageOptimizationReadinessService;
use Maispace\MaiAssets\StaticFileCache\StaticCacheRequestGuard;
use Maispace\MaiAssets\StaticFileCache\StaticFileIndex;
use Maispace\MaiAssets\StaticFileCache\StaticFileWriteRegistry;
use Maispace\MaiAssets\StaticFileCache\StaticHtmlWriterService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;

/**
 * @covers \Maispace\MaiAssets\EventListener\StaticHtmlWriterListener
 */
final class StaticHtmlWriterListenerTest extends TestCase
{
    private FrontendInterface $aboveFoldFrontend;
    private AboveFoldCacheService $aboveFoldCacheService;
    private ExtensionConfiguration $extensionConfiguration;
    private EarlyHintCacheService $earlyHintCacheService;
    private FrontendInterface $earlyHintFrontend;
    private PageOptimizationReadinessService $readinessService;
    private StaticFileWriteRegistry $writeRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireAfterCacheableContentEvent();

        $this->aboveFoldFrontend = $this->createMock(FrontendInterface::class);

        $this->aboveFoldCacheService = (new \ReflectionClass(AboveFoldCacheService::class))
            ->newInstanceWithoutConstructor();
        $cacheProp = new \ReflectionProperty(AboveFoldCacheService::class, 'cache');
        $cacheProp->setValue($this->aboveFoldCacheService, $this->aboveFoldFrontend);

        $this->extensionConfiguration = (new \ReflectionClass(ExtensionConfiguration::class))
            ->newInstanceWithoutConstructor();
        $bucketsProp = new \ReflectionProperty(ExtensionConfiguration::class, 'viewportBuckets');
        $bucketsProp->setValue($this->extensionConfiguration, [
            'mobile' => 768,
            'tablet' => 1024,
            'desktop' => PHP_INT_MAX,
        ]);
        $staticProp = new \ReflectionProperty(ExtensionConfiguration::class, 'enableStaticFileCache');
        $staticProp->setValue($this->extensionConfiguration, true);

        $cacheManager = $this->createMock(CacheManager::class);
        $this->earlyHintFrontend = $this->createMock(FrontendInterface::class);
        $cacheManager
            ->method('getCache')
            ->with('mai_assets_early_hints')
            ->willReturn($this->earlyHintFrontend);

        $this->earlyHintCacheService = new EarlyHintCacheService($cacheManager);

        $this->writeRegistry = new StaticFileWriteRegistry();

        $this->readinessService = new PageOptimizationReadinessService(
            $this->aboveFoldCacheService,
            $this->extensionConfiguration,
        );
    }

    public function testInvokeSkipsWriteForNonShareableRequest(): void
    {
        $this->stubReadyPageWithManifest(42, 0);

        $writer = $this->createMock(StaticHtmlWriterService::class);
        $writer->expects(self::never())->method('writeByPageId');

        $this->makeListener($writer, false)(
            $this->makeEvent('<html>ready</html>', 42, 0),
        );
    }

    public function testInvokeWritesHtmlWhenAllGatesPass(): void
    {
        $this->stubReadyPageWithManifest(42, 0);

        $writer = $this->createMock(StaticHtmlWriterService::class);
        $writer
            ->expects(self::once())
            ->method('writeByPageId')
            ->with('<html>ready</html>', 42, 0)
            ->willReturn(true);

        $listener = $this->makeListener($writer);
        $listener($this->makeEvent('<html>ready</html>', 42, 0));
    }

    public function testInvokeSkipsWhenStaticFileCacheDisabled(): void
    {
        $staticProp = new \ReflectionProperty(ExtensionConfiguration::class, 'enableStaticFileCache');
        $staticProp->setValue($this->extensionConfiguration, false);

        $writer = $this->createMock(StaticHtmlWriterService::class);
        $writer->expects(self::never())->method('writeByPageId');

        $listener = $this->makeListener($writer);
        $listener($this->makeEvent('<html></html>', 42, 0));
    }

    public function testInvokeSkipsWhenCachingDisabled(): void
    {
        $writer = $this->createMock(StaticHtmlWriterService::class);
        $writer->expects(self::never())->method('writeByPageId');

        $request = $this->createRequest(42, 0);
        $event = new AfterCacheableContentIsGeneratedEvent($request, '<html></html>', 'id', false);

        $listener = $this->makeListener($writer);
        $listener($event);
    }

    public function testInvokeSkipsNonGetRequests(): void
    {
        $writer = $this->createMock(StaticHtmlWriterService::class);
        $writer->expects(self::never())->method('writeByPageId');

        $request = $this->createRequest(42, 0, method: 'POST');
        $event = new AfterCacheableContentIsGeneratedEvent($request, '<html></html>', 'id', true);

        $listener = $this->makeListener($writer);
        $listener($event);
    }

    public function testInvokeSkipsUriWithQueryString(): void
    {
        $writer = $this->createMock(StaticHtmlWriterService::class);
        $writer->expects(self::never())->method('writeByPageId');

        $request = $this->createRequest(42, 0, uri: 'https://example.com/page?foo=bar');
        $event = new AfterCacheableContentIsGeneratedEvent($request, '<html></html>', 'id', true);

        $listener = $this->makeListener($writer);
        $listener($event);
    }

    public function testInvokeSkipsWhenRoutingMissing(): void
    {
        $writer = $this->createMock(StaticHtmlWriterService::class);
        $writer->expects(self::never())->method('writeByPageId');

        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn('https://example.com/');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        $event = new AfterCacheableContentIsGeneratedEvent($request, '<html></html>', 'id', true);

        $listener = $this->makeListener($writer);
        $listener($event);
    }

    public function testHtmlTypeNumIsWrittenAsVariantOfThePage(): void
    {
        $this->stubReadyPageWithManifest(42, 0);
        $variant = StaticCacheRequestGuard::variantOf($this->routing(42, '98'));

        $writer = $this->createMock(StaticHtmlWriterService::class);
        $writer->expects(self::once())->method('writeByPageId')->with('<html>print</html>', 42, 0, $variant)->willReturn(true);

        $this->makeListener($writer)($this->makeEvent('<html>print</html>', 42, 0, pageType: '98'));
    }

    public function testInvokeSkipsNonHtmlDocument(): void
    {
        $writer = $this->createMock(StaticHtmlWriterService::class);
        $writer->expects(self::never())->method('writeByPageId');

        $listener = $this->makeListener($writer);
        $listener($this->makeEvent('<?xml version="1.0"?><sitemapindex></sitemapindex>', 42, 0));
    }

    public function testInvokeSkipsWhenPageNotReady(): void
    {
        $this->aboveFoldFrontend
            ->method('get')
            ->with('buckets_42')
            ->willReturn(['mobile']);

        $this->earlyHintFrontend->expects(self::never())->method('get');

        $writer = $this->createMock(StaticHtmlWriterService::class);
        $writer->expects(self::never())->method('writeByPageId');

        $listener = $this->makeListener($writer);
        $listener($this->makeEvent('<html></html>', 42, 0));
    }

    public function testInvokeWritesPagesWithoutEarlyHintsManifest(): void
    {
        // A page without critical assets has no manifest but is still cacheable.
        $this->aboveFoldFrontend
            ->method('get')
            ->with('buckets_42')
            ->willReturn(['mobile', 'tablet', 'desktop']);

        $writer = $this->createMock(StaticHtmlWriterService::class);
        $writer->expects(self::once())->method('writeByPageId')->willReturn(true);

        $this->makeListener($writer)($this->makeEvent('<html></html>', 42, 0));
    }

    public function testNewsDetailPagesAreCachedAsSeparateVariants(): void
    {
        $this->stubReadyPageWithManifest(42, 0);
        $articleA = ['tx_news_pi1' => ['action' => 'detail', 'news' => 7]];
        $articleB = ['tx_news_pi1' => ['action' => 'detail', 'news' => 8]];
        $variantA = StaticCacheRequestGuard::variantOf($this->routing(42, '0', $articleA));
        $variantB = StaticCacheRequestGuard::variantOf($this->routing(42, '0', $articleB));
        self::assertNotSame($variantA, $variantB);

        $written = [];
        $writer = $this->createMock(StaticHtmlWriterService::class);
        $writer->method('writeByPageId')->willReturnCallback(
            static function (string $html, int $page, int $language, string $variant) use (&$written): bool {
                $written[$variant] = $html;

                return true;
            },
        );
        $index = $this->createMock(StaticFileIndex::class);
        $index->expects(self::exactly(2))->method('register')
            ->with(42, 0, self::anything(), self::anything(), self::logicalOr($variantA, $variantB));

        $listener = $this->makeListener($writer, index: $index);
        $listener($this->makeEvent('<html>A</html>', 42, 0, routeArguments: $articleA));
        $listener($this->makeEvent('<html>B</html>', 42, 0, routeArguments: $articleB));

        self::assertSame([$variantA => '<html>A</html>', $variantB => '<html>B</html>'], $written);
    }

    public function testWrittenFileIsRegisteredWithPageCacheTagsAndLifetime(): void
    {
        $this->stubReadyPageWithManifest(42, 0);

        $writer = $this->createMock(StaticHtmlWriterService::class);
        $writer->method('writeByPageId')->willReturn(true);

        $index = $this->createMock(StaticFileIndex::class);
        $index->expects(self::once())->method('register')->with(42, 0, ['pageId_42', 'tx_news_pid_5'], 3600, '');

        $collector = new CacheDataCollector();
        $collector->addCacheTags(new CacheTag('pageId_42'), new CacheTag('tx_news_pid_5', 3600));

        $this->makeListener($writer, index: $index)(
            $this->makeEvent('<html>ready</html>', 42, 0, cacheCollector: $collector),
        );
    }

    public function testWrittenFileIsHandedToTheHeaderRecorderWithItsNonce(): void
    {
        $this->stubReadyPageWithManifest(42, 0);
        $writer = $this->createMock(StaticHtmlWriterService::class);
        $writer->method('writeByPageId')->willReturn(true);
        $nonce = new ConsumableNonce();

        $before = time();
        $this->makeListener($writer)($this->makeEvent('<html>ready</html>', 42, 0, nonce: $nonce));

        $pending = $this->writeRegistry->take();
        self::assertNotNull($pending);
        self::assertSame([42, 0, '', $nonce->value], [$pending['page'], $pending['language'], $pending['variant'], $pending['nonce']]);
        self::assertGreaterThanOrEqual($before + StaticFileIndex::DEFAULT_LIFETIME, $pending['expires']);
    }

    public function testFailedWriteIsNotRegistered(): void
    {
        $this->stubReadyPageWithManifest(42, 0);

        $writer = $this->createMock(StaticHtmlWriterService::class);
        $writer->method('writeByPageId')->willReturn(false);

        $index = $this->createMock(StaticFileIndex::class);
        $index->expects(self::never())->method('register');

        $this->makeListener($writer, index: $index)($this->makeEvent('<html>ready</html>', 42, 0));
    }

    public function testInvokeUsesLanguageUidFromRequest(): void
    {
        $this->aboveFoldFrontend
            ->method('get')
            ->with('buckets_7')
            ->willReturn(['mobile', 'tablet', 'desktop']);

        $this->earlyHintFrontend
            ->method('get')
            ->with('earlyhints_7_2')
            ->willReturn([
                ['href' => '/a.css', 'rel' => 'preload', 'as' => 'style', 'type' => '', 'crossorigin' => ''],
            ]);

        $writer = $this->createMock(StaticHtmlWriterService::class);
        $writer
            ->expects(self::once())
            ->method('writeByPageId')
            ->with('<html>lang</html>', 7, 2)
            ->willReturn(true);

        $listener = $this->makeListener($writer);
        $listener($this->makeEvent('<html>lang</html>', 7, 2));
    }

    /**
     * AfterCacheableContentIsGeneratedEvent is not in the extension autoloader;
     * load it from the project root vendor (same approach as SFC CacheRuleEvent).
     */
    private function requireAfterCacheableContentEvent(): void
    {
        if (class_exists(AfterCacheableContentIsGeneratedEvent::class)) {
            return;
        }

        $path = \dirname(__DIR__, 5)
            . '/vendor/typo3/cms-frontend/Classes/Event/AfterCacheableContentIsGeneratedEvent.php';

        if (!is_file($path)) {
            self::markTestSkipped('typo3/cms-frontend is not available in the project vendor tree');
        }

        require_once $path;
    }

    private function stubReadyPageWithManifest(int $pageUid, int $languageUid): void
    {
        $this->aboveFoldFrontend
            ->method('get')
            ->with('buckets_' . $pageUid)
            ->willReturn(['mobile', 'tablet', 'desktop']);

        $this->earlyHintFrontend
            ->method('get')
            ->with('earlyhints_' . $pageUid . '_' . $languageUid)
            ->willReturn([
                ['href' => '/style.css', 'rel' => 'preload', 'as' => 'style', 'type' => '', 'crossorigin' => ''],
            ]);
    }

    private function makeListener(StaticHtmlWriterService $writer, bool $shareable = true, ?StaticFileIndex $index = null): StaticHtmlWriterListener
    {
        $guard = $this->createMock(StaticCacheRequestGuard::class);
        $guard->method('isShareable')->willReturn($shareable);

        return new StaticHtmlWriterListener(
            $this->extensionConfiguration,
            $writer,
            $this->readinessService,
            $guard,
            $index ?? $this->createMock(StaticFileIndex::class),
            $this->writeRegistry,
        );
    }

    private function makeEvent(
        string $content,
        int $pageUid,
        int $languageUid,
        string $pageType = '0',
        array $routeArguments = [],
        ?CacheDataCollector $cacheCollector = null,
        ?ConsumableNonce $nonce = null,
    ): AfterCacheableContentIsGeneratedEvent {
        return new AfterCacheableContentIsGeneratedEvent(
            $this->createRequest($pageUid, $languageUid, pageType: $pageType, routeArguments: $routeArguments, cacheCollector: $cacheCollector, nonce: $nonce),
            $content,
            'cache-id',
            true,
        );
    }

    /**
     * @return ServerRequestInterface&MockObject
     */
    private function createRequest(
        int $pageUid,
        int $languageUid,
        string $method = 'GET',
        string $uri = 'https://example.com/page',
        string $pageType = '0',
        array $routeArguments = [],
        ?CacheDataCollector $cacheCollector = null,
        ?ConsumableNonce $nonce = null,
    ): ServerRequestInterface {
        $routing = $this->routing($pageUid, $pageType, $routeArguments);

        $language = new readonly class ($languageUid) {
            public function __construct(
                private int $languageId,
            ) {}

            public function getLanguageId(): int
            {
                return $this->languageId;
            }
        };

        return $this->requestFor($method, $uri, $routing, $language, $cacheCollector, $nonce);
    }

    private function routing(int $pageUid, string $pageType = '0', array $routeArguments = []): object
    {
        return new readonly class ($pageUid, $pageType, $routeArguments) {
            public function __construct(
                private int $pageId,
                private string $pageType,
                private array $routeArguments,
            ) {}

            public function getRouteArguments(): array
            {
                return $this->routeArguments;
            }

            public function getPageId(): int
            {
                return $this->pageId;
            }

            public function getPageType(): string
            {
                return $this->pageType;
            }
        };
    }

    private function requestFor(string $method, string $uri, object $routing, object $language, ?CacheDataCollector $cacheCollector, ?ConsumableNonce $nonce = null): ServerRequestInterface
    {
        $uriObject = $this->createMock(UriInterface::class);
        $uriObject->method('__toString')->willReturn($uri);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uriObject);
        $request
            ->method('getAttribute')
            ->willReturnCallback(fn(string $name) => match ($name) {
                'routing' => $routing,
                'language' => $language,
                'frontend.cache.collector' => $cacheCollector,
                'nonce' => $nonce,
                default => null,
            });

        return $request;
    }
}
