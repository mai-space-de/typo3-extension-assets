<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\EventListener;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\EventListener\StaticFileCacheReadinessListener;
use Maispace\MaiAssets\Service\PageOptimizationReadinessService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * PageOptimizationReadinessService, AboveFoldCacheService, and
 * EarlyHintCacheService are `final`, so we build real instances with mock
 * dependencies injected via ReflectionProperty where constructors are final.
 *
 * CacheRuleEvent (SFC\Staticfilecache\Event) is not available via the
 * extension's autoloader, so we require it directly.
 */
final class StaticFileCacheReadinessListenerTest extends TestCase
{
    private FrontendInterface $aboveFoldFrontend;
    private AboveFoldCacheService $aboveFoldCacheService;
    private ExtensionConfiguration $extensionConfiguration;
    private EarlyHintCacheService $earlyHintCacheService;
    private FrontendInterface $earlyHintFrontend;

    protected function setUp(): void
    {
        parent::setUp();

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

        $cacheManager = $this->createMock(CacheManager::class);
        $this->earlyHintFrontend = $this->createMock(FrontendInterface::class);
        $cacheManager
            ->method('getCache')
            ->with('mai_assets_early_hints')
            ->willReturn($this->earlyHintFrontend);

        $this->earlyHintCacheService = new EarlyHintCacheService($cacheManager);
    }

    private function makeReadinessService(): PageOptimizationReadinessService
    {
        return new PageOptimizationReadinessService(
            $this->aboveFoldCacheService,
            $this->extensionConfiguration,
        );
    }

    /**
     * Load the CacheRuleEvent class from the .lookup/staticfilecache directory
     * since it is not available via the extension's autoloader.
     */
    private static function requireCacheRuleEvent(): void
    {
        $baseDir = \dirname(__DIR__, 5) . '/.lookup/staticfilecache/Classes/Event/';
        require_once $baseDir . 'CacheRuleEventInterface.php';
        require_once $baseDir . 'CacheRuleEvent.php';
    }

    /**
     * @return object{getPageRecord(): array}
     */
    private function createPageInformation(int $pageUid): object
    {
        return new class($pageUid) {
            public function __construct(
                private readonly int $pageUid,
            ) {}

            /** @return array{uid: int} */
            public function getPageRecord(): array
            {
                return ['uid' => $this->pageUid];
            }
        };
    }

    /**
     * @return ServerRequestInterface&MockObject
     */
    private function createRequestWithPageUid(int $pageUid, ?int $languageUid = 0): ServerRequestInterface
    {
        $pageInformation = $this->createPageInformation($pageUid);

        $language = null;
        if ($languageUid !== null) {
            $language = new class($languageUid) {
                public function __construct(
                    private readonly int $languageId,
                ) {}

                public function getLanguageId(): int
                {
                    return $this->languageId;
                }
            };
        }

        $request = $this->createMock(ServerRequestInterface::class);
        $request
            ->method('getAttribute')
            ->willReturnCallback(function (string $name) use ($pageInformation, $language) {
                return match ($name) {
                    'frontend.page.information' => $pageInformation,
                    'language' => $language,
                    default => null,
                };
            });

        return $request;
    }

    public function testAllowsCachingWhenReadyAndManifestExists(): void
    {
        self::requireCacheRuleEvent();

        $this->aboveFoldFrontend
            ->method('get')
            ->with('buckets_42')
            ->willReturn(['mobile', 'tablet', 'desktop']);

        $this->earlyHintFrontend
            ->method('get')
            ->with('earlyhints_42_0')
            ->willReturn([
                ['href' => '/style.css', 'rel' => 'preload', 'as' => 'style', 'type' => '', 'crossorigin' => ''],
            ]);

        $request = $this->createRequestWithPageUid(42, 0);
        $response = $this->createMock(ResponseInterface::class);
        $event = new \SFC\Staticfilecache\Event\CacheRuleEvent($request, [], false, $response);

        $listener = new StaticFileCacheReadinessListener(
            $this->makeReadinessService(),
            $this->earlyHintCacheService,
        );
        $listener($event);

        self::assertFalse($event->isSkipProcessing());
        self::assertSame([], $event->getExplanation());
    }

    public function testGatesWhenPageNotReady(): void
    {
        self::requireCacheRuleEvent();

        $this->aboveFoldFrontend
            ->method('get')
            ->with('buckets_42')
            ->willReturn(['mobile']);

        $this->earlyHintFrontend
            ->expects(self::never())
            ->method('get');

        $request = $this->createRequestWithPageUid(42, 0);
        $response = $this->createMock(ResponseInterface::class);
        $event = new \SFC\Staticfilecache\Event\CacheRuleEvent($request, [], false, $response);

        $listener = new StaticFileCacheReadinessListener(
            $this->makeReadinessService(),
            $this->earlyHintCacheService,
        );
        $listener($event);

        self::assertTrue($event->isSkipProcessing());
        self::assertNotEmpty($event->getExplanation());
        $explanations = $event->getExplanation();
        $explanationText = reset($explanations);
        self::assertStringContainsString('missing viewport bucket data', $explanationText);
    }

    public function testGatesWhenManifestMissing(): void
    {
        self::requireCacheRuleEvent();

        $this->aboveFoldFrontend
            ->method('get')
            ->with('buckets_42')
            ->willReturn(['mobile', 'tablet', 'desktop']);

        $this->earlyHintFrontend
            ->method('get')
            ->with('earlyhints_42_0')
            ->willReturn(false);

        $request = $this->createRequestWithPageUid(42, 0);
        $response = $this->createMock(ResponseInterface::class);
        $event = new \SFC\Staticfilecache\Event\CacheRuleEvent($request, [], false, $response);

        $listener = new StaticFileCacheReadinessListener(
            $this->makeReadinessService(),
            $this->earlyHintCacheService,
        );
        $listener($event);

        self::assertTrue($event->isSkipProcessing());
        self::assertNotEmpty($event->getExplanation());
        $explanations = $event->getExplanation();
        $explanationText = reset($explanations);
        self::assertStringContainsString('No early-hints manifest', $explanationText);
    }

    public function testReturnsEarlyWhenNoPageInformation(): void
    {
        self::requireCacheRuleEvent();

        $this->aboveFoldFrontend
            ->expects(self::never())
            ->method('get');
        $this->earlyHintFrontend
            ->expects(self::never())
            ->method('get');

        $request = $this->createMock(ServerRequestInterface::class);
        $request
            ->method('getAttribute')
            ->with('frontend.page.information')
            ->willReturn(null);

        $response = $this->createMock(ResponseInterface::class);
        $event = new \SFC\Staticfilecache\Event\CacheRuleEvent($request, [], false, $response);

        $listener = new StaticFileCacheReadinessListener(
            $this->makeReadinessService(),
            $this->earlyHintCacheService,
        );
        $listener($event);

        self::assertFalse($event->isSkipProcessing());
        self::assertSame([], $event->getExplanation());
    }

    public function testReturnsEarlyWhenPageUidIsZero(): void
    {
        self::requireCacheRuleEvent();

        $this->aboveFoldFrontend
            ->expects(self::never())
            ->method('get');
        $this->earlyHintFrontend
            ->expects(self::never())
            ->method('get');

        $request = $this->createRequestWithPageUid(0, 0);
        $response = $this->createMock(ResponseInterface::class);
        $event = new \SFC\Staticfilecache\Event\CacheRuleEvent($request, [], false, $response);

        $listener = new StaticFileCacheReadinessListener(
            $this->makeReadinessService(),
            $this->earlyHintCacheService,
        );
        $listener($event);

        self::assertFalse($event->isSkipProcessing());
        self::assertSame([], $event->getExplanation());
    }

    public function testUsesLanguageUidFromRequest(): void
    {
        self::requireCacheRuleEvent();

        $this->aboveFoldFrontend
            ->method('get')
            ->with('buckets_7')
            ->willReturn(['mobile', 'tablet', 'desktop']);

        $this->earlyHintFrontend
            ->method('get')
            ->with('earlyhints_7_1')
            ->willReturn(false);

        $pageInformation = $this->createPageInformation(7);

        $language = new class(1) {
            public function __construct(
                private readonly int $languageId,
            ) {}

            public function getLanguageId(): int
            {
                return $this->languageId;
            }
        };

        $request = $this->createMock(ServerRequestInterface::class);
        $request
            ->method('getAttribute')
            ->willReturnCallback(function (string $name) use ($pageInformation, $language) {
                return match ($name) {
                    'frontend.page.information' => $pageInformation,
                    'language' => $language,
                    default => null,
                };
            });

        $response = $this->createMock(ResponseInterface::class);
        $event = new \SFC\Staticfilecache\Event\CacheRuleEvent($request, [], false, $response);

        $listener = new StaticFileCacheReadinessListener(
            $this->makeReadinessService(),
            $this->earlyHintCacheService,
        );
        $listener($event);

        self::assertTrue($event->isSkipProcessing());
    }

    public function testDefaultsToLanguageUidZeroWhenNoLanguageAttribute(): void
    {
        self::requireCacheRuleEvent();

        $this->aboveFoldFrontend
            ->method('get')
            ->with('buckets_7')
            ->willReturn(['mobile', 'tablet', 'desktop']);

        $this->earlyHintFrontend
            ->method('get')
            ->with('earlyhints_7_0')
            ->willReturn(false);

        $pageInformation = $this->createPageInformation(7);

        $request = $this->createMock(ServerRequestInterface::class);
        $request
            ->method('getAttribute')
            ->willReturnCallback(function (string $name) use ($pageInformation) {
                return match ($name) {
                    'frontend.page.information' => $pageInformation,
                    default => null,
                };
            });

        $response = $this->createMock(ResponseInterface::class);
        $event = new \SFC\Staticfilecache\Event\CacheRuleEvent($request, [], false, $response);

        $listener = new StaticFileCacheReadinessListener(
            $this->makeReadinessService(),
            $this->earlyHintCacheService,
        );
        $listener($event);

        self::assertTrue($event->isSkipProcessing());
    }
}
