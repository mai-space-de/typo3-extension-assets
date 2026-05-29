<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\EventListener;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\EventListener\StaticHtmlWriterListener;
use Maispace\MaiAssets\Service\PageOptimizationReadinessService;
use Maispace\MaiAssets\StaticFileCache\StaticHtmlWriterService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
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

    protected function setUp(): void
    {
        parent::setUp();

        self::requireAfterCacheableContentEvent();

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

        $this->readinessService = new PageOptimizationReadinessService(
            $this->aboveFoldCacheService,
            $this->extensionConfiguration,
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

    public function testInvokeSkipsWhenEarlyHintsManifestMissing(): void
    {
        $this->aboveFoldFrontend
            ->method('get')
            ->with('buckets_42')
            ->willReturn(['mobile', 'tablet', 'desktop']);

        $this->earlyHintFrontend
            ->method('get')
            ->with('earlyhints_42_0')
            ->willReturn(false);

        $writer = $this->createMock(StaticHtmlWriterService::class);
        $writer->expects(self::never())->method('writeByPageId');

        $listener = $this->makeListener($writer);
        $listener($this->makeEvent('<html></html>', 42, 0));
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
    private static function requireAfterCacheableContentEvent(): void
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

    private function makeListener(StaticHtmlWriterService $writer): StaticHtmlWriterListener
    {
        return new StaticHtmlWriterListener(
            $this->extensionConfiguration,
            $writer,
            $this->readinessService,
            $this->earlyHintCacheService,
        );
    }

    private function makeEvent(string $content, int $pageUid, int $languageUid): AfterCacheableContentIsGeneratedEvent
    {
        return new AfterCacheableContentIsGeneratedEvent(
            $this->createRequest($pageUid, $languageUid),
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
    ): ServerRequestInterface {
        $routing = new class($pageUid) {
            public function __construct(
                private readonly int $pageId,
            ) {}

            public function getPageId(): int
            {
                return $this->pageId;
            }
        };

        $language = new class($languageUid) {
            public function __construct(
                private readonly int $languageId,
            ) {}

            public function getLanguageId(): int
            {
                return $this->languageId;
            }
        };

        $uriObject = $this->createMock(UriInterface::class);
        $uriObject->method('__toString')->willReturn($uri);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uriObject);
        $request
            ->method('getAttribute')
            ->willReturnCallback(function (string $name) use ($routing, $language) {
                return match ($name) {
                    'routing' => $routing,
                    'language' => $language,
                    default => null,
                };
            });

        return $request;
    }
}
