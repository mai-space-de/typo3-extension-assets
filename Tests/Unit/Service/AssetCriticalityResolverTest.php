<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Service;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Service\AssetCriticalityResolver;
use Maispace\MaiAssets\Service\CriticalDetectionService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;

/**
 * AboveFoldCacheService and CriticalDetectionService are `final`, so we bypass
 * their constructors via ReflectionClass::newInstanceWithoutConstructor() and
 * inject mock dependencies through ReflectionProperty.
 *
 * isElementAboveFold() delegates to CriticalDetectionService::isCritical() which
 * executes DB queries — that path is covered by functional/integration tests.
 */
final class AssetCriticalityResolverTest extends TestCase
{
    private AboveFoldCacheService $cacheService;
    private FrontendInterface $cacheFrontend;
    private ExtensionConfiguration $extensionConfiguration;

    protected function setUp(): void
    {
        $this->cacheFrontend = $this->createMock(FrontendInterface::class);

        // Bypass the final constructor; only inject the cache frontend used by getAllCriticalUids
        $this->cacheService = (new \ReflectionClass(AboveFoldCacheService::class))
            ->newInstanceWithoutConstructor();
        $cacheProp = new \ReflectionProperty(AboveFoldCacheService::class, 'cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->cacheService, $this->cacheFrontend);

        // ExtensionConfiguration — seed viewportBuckets via globals
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = [
            'viewportBuckets' => ['mobile' => 768, 'tablet' => 1024, 'desktop' => 99999],
        ];
        $this->extensionConfiguration = new ExtensionConfiguration(
            (new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor()
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets']);
    }

    public function testPageHasObserverDataReturnsFalseWhenNoBucketsInCache(): void
    {
        // 'buckets_{pageUid}' key is not in cache → getAllCriticalUids returns []
        $this->cacheFrontend->method('get')->with('buckets_42')->willReturn(false);

        $resolver = $this->makeResolver();
        self::assertFalse($resolver->pageHasObserverData(42));
    }

    public function testPageHasObserverDataReturnsTrueWhenUidsExistForPage(): void
    {
        // get('buckets_42') → ['desktop'], get('page_42_desktop') → [1, 2, 3]
        $this->cacheFrontend->method('get')->willReturnMap([
            ['buckets_42', ['desktop']],
            ['page_42_desktop', [1, 2, 3]],
        ]);

        $resolver = $this->makeResolver();
        self::assertTrue($resolver->pageHasObserverData(42));
    }

    public function testPageHasObserverDataReturnsFalseWhenBucketExistsButUidsEmpty(): void
    {
        $this->cacheFrontend->method('get')->willReturnMap([
            ['buckets_99', ['desktop']],
            ['page_99_desktop', []],
        ]);

        $resolver = $this->makeResolver();
        self::assertFalse($resolver->pageHasObserverData(99));
    }

    public function testPageHasObserverDataIsFalseForPageZero(): void
    {
        // Page 0 has no meaningful observer data; the method should still be callable
        $this->cacheFrontend->method('get')->willReturn(false);

        $resolver = $this->makeResolver();
        self::assertFalse($resolver->pageHasObserverData(0));
    }

    public function testPageHasCompleteObserverDataReturnsFalseWhenNoBuckets(): void
    {
        $this->cacheFrontend->method('get')->willReturn(false);

        $resolver = $this->makeResolver();
        self::assertFalse($resolver->pageHasCompleteObserverData(42));
    }

    public function testPageHasCompleteObserverDataReturnsFalseWhenPartialBuckets(): void
    {
        // Only 'desktop' bucket stored, but mobile and tablet are also configured
        $this->cacheFrontend->method('get')->willReturnMap([
            ['buckets_42', ['desktop']],
            ['page_42_desktop', [1, 2, 3]],
        ]);

        $resolver = $this->makeResolver();
        self::assertFalse($resolver->pageHasCompleteObserverData(42));
    }

    public function testPageHasCompleteObserverDataReturnsTrueWhenAllBucketsHaveData(): void
    {
        // All three configured buckets (mobile, tablet, desktop) have data
        $this->cacheFrontend->method('get')->willReturnMap([
            ['buckets_42', ['mobile', 'tablet', 'desktop']],
            ['page_42_mobile', [1, 2]],
            ['page_42_tablet', [3, 4]],
            ['page_42_desktop', [5, 6, 7]],
        ]);

        $resolver = $this->makeResolver();
        self::assertTrue($resolver->pageHasCompleteObserverData(42));
    }

    public function testPageHasCompleteObserverDataReturnsFalseWhenAllBucketsExistButUidsEmpty(): void
    {
        // All buckets are in the index but all have empty UID arrays
        $this->cacheFrontend->method('get')->willReturnMap([
            ['buckets_99', ['mobile', 'tablet', 'desktop']],
            ['page_99_mobile', []],
            ['page_99_tablet', []],
            ['page_99_desktop', []],
        ]);

        $resolver = $this->makeResolver();
        self::assertFalse($resolver->pageHasCompleteObserverData(99));
    }

    public function testPageHasCompleteObserverDataIsFalseForPageZero(): void
    {
        $this->cacheFrontend->method('get')->willReturn(false);

        $resolver = $this->makeResolver();
        self::assertFalse($resolver->pageHasCompleteObserverData(0));
    }

    public function testPageHasObserverDataReturnsTrueWhenOnlyOneBucketHasUids(): void
    {
        // pageHasObserverData is more permissive — should return true with just one bucket
        $this->cacheFrontend->method('get')->willReturnMap([
            ['buckets_42', ['desktop']],
            ['page_42_desktop', [1, 2, 3]],
        ]);

        $resolver = $this->makeResolver();
        self::assertTrue($resolver->pageHasObserverData(42));
    }

    private function makeResolver(): AssetCriticalityResolver
    {
        // CriticalDetectionService::isCritical() issues DB queries, so we bypass
        // construction and leave the detection service unused in page-level tests.
        $detectionService = (new \ReflectionClass(CriticalDetectionService::class))
            ->newInstanceWithoutConstructor();

        return new AssetCriticalityResolver($this->cacheService, $detectionService, $this->extensionConfiguration);
    }
}
