<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Service;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Service\PageOptimizationReadinessService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * AboveFoldCacheService and ExtensionConfiguration are `final`, so we bypass
 * their constructors via ReflectionClass::newInstanceWithoutConstructor() and
 * inject mock dependencies through ReflectionProperty.
 */
final class PageOptimizationReadinessServiceTest extends TestCase
{
    private AboveFoldCacheService $aboveFoldCacheService;
    private FrontendInterface $cacheFrontend;
    private ExtensionConfiguration $extensionConfiguration;

    protected function setUp(): void
    {
        $this->cacheFrontend = $this->createMock(FrontendInterface::class);

        // Bypass final constructor of AboveFoldCacheService
        $this->aboveFoldCacheService = new \ReflectionClass(AboveFoldCacheService::class)
            ->newInstanceWithoutConstructor();
        $cacheProp = new \ReflectionProperty(AboveFoldCacheService::class, 'cache');
        $cacheProp->setValue($this->aboveFoldCacheService, $this->cacheFrontend);

        // Bypass final constructor of ExtensionConfiguration
        $this->extensionConfiguration = new \ReflectionClass(ExtensionConfiguration::class)
            ->newInstanceWithoutConstructor();
        $bucketsProp = new \ReflectionProperty(ExtensionConfiguration::class, 'viewportBuckets');
        $bucketsProp->setValue($this->extensionConfiguration, [
            'mobile' => 768,
            'tablet' => 1024,
            'desktop' => PHP_INT_MAX,
        ]);
    }

    private function makeService(): PageOptimizationReadinessService
    {
        return new PageOptimizationReadinessService(
            $this->aboveFoldCacheService,
            $this->extensionConfiguration,
        );
    }

    public function testIsReadyReturnsTrueWhenAllBucketsPresent(): void
    {
        $this->cacheFrontend->method('get')
            ->with('buckets_42')
            ->willReturn(['mobile', 'tablet', 'desktop']);

        $service = $this->makeService();
        self::assertTrue($service->isReady(42));
    }

    public function testIsReadyReturnsTrueWhenBucketsInAnyOrder(): void
    {
        $this->cacheFrontend->method('get')
            ->with('buckets_42')
            ->willReturn(['desktop', 'mobile', 'tablet']);

        $service = $this->makeService();
        self::assertTrue($service->isReady(42));
    }

    public function testIsReadyReturnsFalseWhenSomeBucketsMissing(): void
    {
        $this->cacheFrontend->method('get')
            ->with('buckets_42')
            ->willReturn(['mobile', 'desktop']);

        $service = $this->makeService();
        self::assertFalse($service->isReady(42));
    }

    public function testIsReadyReturnsFalseWhenNoBucketsStored(): void
    {
        // Cache returns false (no entry) for 'buckets_42'
        $this->cacheFrontend->method('get')
            ->with('buckets_42')
            ->willReturn(false);

        $service = $this->makeService();
        self::assertFalse($service->isReady(42));
    }

    public function testIsReadyReturnsFalseForPageZero(): void
    {
        // No cache call should be made for pageUid 0
        $this->cacheFrontend->expects(self::never())->method('get');

        $service = $this->makeService();
        self::assertFalse($service->isReady(0));
    }

    public function testIsReadyReturnsFalseForNegativePageUid(): void
    {
        $this->cacheFrontend->expects(self::never())->method('get');

        $service = $this->makeService();
        self::assertFalse($service->isReady(-1));
    }

    public function testIsReadyReturnsFalseForEmptyConfiguredBuckets(): void
    {
        // Override to empty viewportBuckets
        $bucketsProp = new \ReflectionProperty(ExtensionConfiguration::class, 'viewportBuckets');
        $bucketsProp->setValue($this->extensionConfiguration, []);

        $this->cacheFrontend->expects(self::never())->method('get');

        $service = $this->makeService();
        self::assertFalse($service->isReady(42));
    }

    public function testIsReadyReturnsFalseWhenOnlyExtraBuckets(): void
    {
        // Stored buckets include names not in configuration, but are missing a required one
        $this->cacheFrontend->method('get')
            ->with('buckets_42')
            ->willReturn(['mobile', 'tablet', 'ultrawide']);

        $service = $this->makeService();
        self::assertFalse($service->isReady(42));
    }
}
