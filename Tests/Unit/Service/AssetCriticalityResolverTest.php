<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Service;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Service\AssetCriticalityResolver;
use Maispace\MaiAssets\Service\CriticalDetectionService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

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

    protected function setUp(): void
    {
        $this->cacheFrontend = $this->createMock(FrontendInterface::class);

        // Bypass the final constructor; only inject the cache frontend used by getAllCriticalUids
        $this->cacheService = (new \ReflectionClass(AboveFoldCacheService::class))
            ->newInstanceWithoutConstructor();
        $cacheProp = new \ReflectionProperty(AboveFoldCacheService::class, 'cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->cacheService, $this->cacheFrontend);
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

    private function makeResolver(): AssetCriticalityResolver
    {
        // CriticalDetectionService::isCritical() issues DB queries, so we bypass
        // construction and leave the detection service unused in page-level tests.
        $detectionService = (new \ReflectionClass(CriticalDetectionService::class))
            ->newInstanceWithoutConstructor();

        return new AssetCriticalityResolver($this->cacheService, $detectionService);
    }
}
