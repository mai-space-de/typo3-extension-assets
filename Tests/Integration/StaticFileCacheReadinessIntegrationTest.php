<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Integration;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidate;
use Maispace\MaiAssets\EventListener\StaticFileCacheReadinessListener;
use Maispace\MaiAssets\Service\PageOptimizationReadinessService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * Integration tests for the static file cache readiness pipeline.
 *
 * Unlike the unit tests (which mock the TYPO3 cache frontend), these tests
 * use a real in-memory cache backend (TransientMemoryBackend) so the full
 * read/write cycle of AboveFoldCacheService and EarlyHintCacheService is
 * exercised against a real TYPO3 cache stack.
 *
 * The StaticFileCacheReadinessListener unit tests already verify the
 * listener's decision logic. These integration tests verify that the
 * underlying services correctly interact with the TYPO3 caching framework,
 * ensuring the readiness gate works end-to-end:
 *
 * - Incomplete viewport buckets → PageOptimizationReadinessService::isReady
 *   returns false → listener gates on cache writes.
 * - All configured buckets filled → isReady returns true → listener checks
 *   early-hints manifest → allows cache writes only when manifest exists.
 */
final class StaticFileCacheReadinessIntegrationTest extends TestCase
{
    private TransientMemoryBackend $aboveFoldBackend;
    private VariableFrontend $aboveFoldFrontend;
    private TransientMemoryBackend $earlyHintBackend;
    private VariableFrontend $earlyHintFrontend;
    private ExtensionConfiguration $extensionConfiguration;
    private AboveFoldCacheService $aboveFoldCacheService;
    private EarlyHintCacheService $earlyHintCacheService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aboveFoldBackend = new TransientMemoryBackend([]);
        $this->aboveFoldFrontend = new VariableFrontend(
            'mai_assets_above_fold',
            $this->aboveFoldBackend,
        );

        $this->earlyHintBackend = new TransientMemoryBackend([]);
        $this->earlyHintFrontend = new VariableFrontend(
            'mai_assets_early_hints',
            $this->earlyHintBackend,
        );

        $this->extensionConfiguration = $this->createExtensionConfiguration();

        $this->aboveFoldCacheService = $this->createAboveFoldCacheService();
        $this->earlyHintCacheService = $this->createEarlyHintCacheService();
    }

    protected function tearDown(): void
    {
        $this->aboveFoldFrontend->flush();
        $this->earlyHintFrontend->flush();
    }

    private function createExtensionConfiguration(): ExtensionConfiguration
    {
        $config = (new \ReflectionClass(ExtensionConfiguration::class))
            ->newInstanceWithoutConstructor();

        (new \ReflectionProperty(ExtensionConfiguration::class, 'viewportBuckets'))
            ->setValue($config, [
                'mobile'  => 768,
                'tablet'  => 1024,
                'desktop' => PHP_INT_MAX,
            ]);

        return $config;
    }

    private function createAboveFoldCacheService(): AboveFoldCacheService
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager
            ->method('getCache')
            ->with('mai_assets_above_fold')
            ->willReturn($this->aboveFoldFrontend);

        return new \Maispace\MaiAssets\Cache\AboveFoldCacheService(
            $cacheManager,
            $this->createStub(\Psr\EventDispatcher\EventDispatcherInterface::class),
            $this->createStub(\Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService::class),
        );
    }

    private function createEarlyHintCacheService(): EarlyHintCacheService
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager
            ->method('getCache')
            ->with('mai_assets_early_hints')
            ->willReturn($this->earlyHintFrontend);

        return new EarlyHintCacheService($cacheManager);
    }

    private function createReadinessService(): PageOptimizationReadinessService
    {
        return new PageOptimizationReadinessService(
            $this->aboveFoldCacheService,
            $this->extensionConfiguration,
        );
    }

    /**
     * Write critical UIDs for the given page and bucket directly into the
     * real in-memory cache, bypassing AboveFoldCacheService's updateCriticalUids
     * to avoid triggering events.
     */
    private function writeBucketData(int $pageUid, string $bucket, array $uids): void
    {
        $key = 'page_' . $pageUid . '_' . $bucket;
        $this->aboveFoldFrontend->set($key, $uids);

        $indexKey = 'buckets_' . $pageUid;
        $buckets = $this->aboveFoldFrontend->get($indexKey);
        if (!is_array($buckets)) {
            $buckets = [];
        }
        if (!in_array($bucket, $buckets, true)) {
            $buckets[] = $bucket;
        }
        $this->aboveFoldFrontend->set($indexKey, $buckets);
    }

    private function writeEarlyHintManifest(int $pageUid, int $languageUid): void
    {
        $key = 'earlyhints_' . $pageUid . '_' . $languageUid;
        $this->earlyHintFrontend->set($key, [
            [
                'href'        => '/assets/style.css',
                'rel'         => 'preload',
                'as'          => 'style',
                'type'        => '',
                'crossorigin' => '',
            ],
        ]);
    }

    public function testIsReadyReturnsFalseWhenNoBuckets(): void
    {
        $readiness = $this->createReadinessService();

        self::assertFalse($readiness->isReady(42));
    }

    public function testIsReadyReturnsFalseWhenIncompleteBuckets(): void
    {
        $this->writeBucketData(42, 'mobile', [1, 2, 3]);
        $this->writeBucketData(42, 'tablet', [4, 5]);

        $readiness = $this->createReadinessService();

        self::assertFalse($readiness->isReady(42));
    }

    public function testIsReadyReturnsTrueWhenAllBucketsFilled(): void
    {
        $this->writeBucketData(42, 'mobile', [1, 2, 3]);
        $this->writeBucketData(42, 'tablet', [4, 5]);
        $this->writeBucketData(42, 'desktop', [6, 7, 8]);

        $readiness = $this->createReadinessService();

        self::assertTrue($readiness->isReady(42));
    }

    public function testIsReadyReturnsFalseForZeroPageUid(): void
    {
        $readiness = $this->createReadinessService();

        self::assertFalse($readiness->isReady(0));
    }

    public function testIsReadyReturnsFalseForNegativePageUid(): void
    {
        $readiness = $this->createReadinessService();

        self::assertFalse($readiness->isReady(-1));
    }

    public function testEarlyHintLoadReturnsEmptyWhenNoManifest(): void
    {
        $candidates = $this->earlyHintCacheService->load(42, 0);

        self::assertSame([], $candidates);
    }

    public function testEarlyHintLoadReturnsManifestAfterWrite(): void
    {
        $this->writeEarlyHintManifest(42, 0);

        $candidates = $this->earlyHintCacheService->load(42, 0);

        self::assertCount(1, $candidates);
        self::assertSame('/assets/style.css', $candidates[0]->href);
        self::assertSame('preload', $candidates[0]->rel);
        self::assertSame('style', $candidates[0]->as);
    }

    public function testEarlyHintManifestIsolatedPerLanguage(): void
    {
        $this->writeEarlyHintManifest(42, 0);

        $candidatesDe = $this->earlyHintCacheService->load(42, 0);
        $candidatesEn = $this->earlyHintCacheService->load(42, 1);

        self::assertNotEmpty($candidatesDe);
        self::assertSame([], $candidatesEn);
    }

    /**
     * End-to-end: incomplete buckets → listener gates on CacheRuleEvent.
     */
    public function testListenerGatesWhenBucketsIncomplete(): void
    {
        $this->requireCacheRuleEvent();

        $this->writeBucketData(42, 'mobile', [1, 2]);

        $listener = new StaticFileCacheReadinessListener(
            $this->createReadinessService(),
            $this->earlyHintCacheService,
        );

        $request = $this->createRequestWithPageUid(42, 0);
        $response = $this->createMock(ResponseInterface::class);
        $event = new \SFC\Staticfilecache\Event\CacheRuleEvent($request, [], false, $response);

        $listener($event);

        self::assertTrue($event->isSkipProcessing());
        $explanations = $event->getExplanation();
        self::assertStringContainsString('missing viewport bucket data', reset($explanations));
    }

    /**
     * End-to-end: all buckets filled + manifest exists → listener allows caching.
     */
    public function testListenerAllowsWhenReadyAndManifestExists(): void
    {
        $this->requireCacheRuleEvent();

        $this->writeBucketData(42, 'mobile', [1, 2, 3]);
        $this->writeBucketData(42, 'tablet', [4, 5]);
        $this->writeBucketData(42, 'desktop', [6, 7, 8]);
        $this->writeEarlyHintManifest(42, 0);

        $listener = new StaticFileCacheReadinessListener(
            $this->createReadinessService(),
            $this->earlyHintCacheService,
        );

        $request = $this->createRequestWithPageUid(42, 0);
        $response = $this->createMock(ResponseInterface::class);
        $event = new \SFC\Staticfilecache\Event\CacheRuleEvent($request, [], false, $response);

        $listener($event);

        self::assertFalse($event->isSkipProcessing());
        self::assertSame([], $event->getExplanation());
    }

    /**
     * End-to-end: all buckets filled but NO manifest → listener gates.
     */
    public function testListenerGatesWhenManifestMissing(): void
    {
        $this->requireCacheRuleEvent();

        $this->writeBucketData(42, 'mobile', [1, 2, 3]);
        $this->writeBucketData(42, 'tablet', [4, 5]);
        $this->writeBucketData(42, 'desktop', [6, 7, 8]);

        $listener = new StaticFileCacheReadinessListener(
            $this->createReadinessService(),
            $this->earlyHintCacheService,
        );

        $request = $this->createRequestWithPageUid(42, 0);
        $response = $this->createMock(ResponseInterface::class);
        $event = new \SFC\Staticfilecache\Event\CacheRuleEvent($request, [], false, $response);

        $listener($event);

        self::assertTrue($event->isSkipProcessing());
        $explanations = $event->getExplanation();
        self::assertStringContainsString('No early-hints manifest', reset($explanations));
    }

    /**
     * Integration: AboveFoldCacheService reads back what was written.
     */
    public function testAboveFoldCacheServiceReadsWrittenData(): void
    {
        $this->writeBucketData(99, 'mobile', [10, 20, 30]);

        $uids = $this->aboveFoldCacheService->getCriticalUids(99, 'mobile');
        self::assertSame([10, 20, 30], $uids);

        $buckets = $this->aboveFoldCacheService->getBucketNames(99);
        self::assertSame(['mobile'], $buckets);
    }

    /**
     * Integration: PageOptimizationReadinessService with real cache stack.
     */
    public function testReadinessServiceEndToEndWithRealCache(): void
    {
        $readiness = $this->createReadinessService();

        self::assertFalse($readiness->isReady(1), 'Not ready before any data');

        $this->writeBucketData(1, 'mobile', [1]);
        self::assertFalse($readiness->isReady(1), 'Still incomplete');

        $this->writeBucketData(1, 'tablet', [2]);
        self::assertFalse($readiness->isReady(1), 'Still incomplete');

        $this->writeBucketData(1, 'desktop', [3]);
        self::assertTrue($readiness->isReady(1), 'Ready when all buckets filled');
    }

    private function requireCacheRuleEvent(): void
    {
        $baseDir = \dirname(__DIR__, 4) . '/.lookup/staticfilecache/Classes/Event/';
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

    private function createRequestWithPageUid(int $pageUid, int $languageUid = 0): ServerRequestInterface
    {
        $pageInformation = $this->createPageInformation($pageUid);

        $language = new class($languageUid) {
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

        return $request;
    }
}
