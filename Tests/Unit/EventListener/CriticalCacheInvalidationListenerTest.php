<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\EventListener;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Cache\InvalidationService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Event\AfterCriticalUidsUpdatedEvent;
use Maispace\MaiAssets\EventListener\CriticalCacheInvalidationListener;
use Maispace\MaiAssets\Service\PageOptimizationReadinessService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * PageOptimizationReadinessService is final — use a real instance with mocked cache.
 */
final class CriticalCacheInvalidationListenerTest extends TestCase
{
    private FrontendInterface $aboveFoldFrontend;
    private AboveFoldCacheService $aboveFoldCacheService;
    private ExtensionConfiguration $extensionConfiguration;
    private PageOptimizationReadinessService $readinessService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aboveFoldFrontend = $this->createMock(FrontendInterface::class);

        $this->aboveFoldCacheService = new \ReflectionClass(AboveFoldCacheService::class)
            ->newInstanceWithoutConstructor();
        $cacheProp = new \ReflectionProperty(AboveFoldCacheService::class, 'cache');
        $cacheProp->setValue($this->aboveFoldCacheService, $this->aboveFoldFrontend);

        $this->extensionConfiguration = new \ReflectionClass(ExtensionConfiguration::class)
            ->newInstanceWithoutConstructor();
        $bucketsProp = new \ReflectionProperty(ExtensionConfiguration::class, 'viewportBuckets');
        $bucketsProp->setValue($this->extensionConfiguration, [
            'mobile' => 768,
            'tablet' => 1024,
            'desktop' => PHP_INT_MAX,
        ]);

        $this->readinessService = new PageOptimizationReadinessService(
            $this->aboveFoldCacheService,
            $this->extensionConfiguration,
        );
    }

    public function testInvokeDelegatesToInvalidationServiceWhenPageIsReady(): void
    {
        $this->aboveFoldFrontend
            ->method('get')
            ->with('buckets_42')
            ->willReturn(['mobile', 'tablet', 'desktop']);

        $invalidationService = $this->createMock(InvalidationService::class);
        $invalidationService
            ->expects(self::once())
            ->method('invalidateAfterBucketUpdate')
            ->with(42, 'desktop');

        $event = new AfterCriticalUidsUpdatedEvent(42, 'desktop', [], [1, 2]);

        $listener = new CriticalCacheInvalidationListener($invalidationService, $this->readinessService);
        $listener($event);
    }

    public function testInvokeSkipsInvalidationWhenPageIsNotReady(): void
    {
        $this->aboveFoldFrontend
            ->method('get')
            ->with('buckets_7')
            ->willReturn(['mobile']);

        $invalidationService = $this->createMock(InvalidationService::class);
        $invalidationService->expects(self::never())->method('invalidateAfterBucketUpdate');

        $event = new AfterCriticalUidsUpdatedEvent(7, 'mobile', [3], [3, 4]);

        $listener = new CriticalCacheInvalidationListener($invalidationService, $this->readinessService);
        $listener($event);
    }

    public function testInvokePassesCorrectPageUidAndBucketWhenReady(): void
    {
        $this->aboveFoldFrontend
            ->method('get')
            ->with('buckets_99')
            ->willReturn(['mobile', 'tablet', 'desktop']);

        $invalidationService = $this->createMock(InvalidationService::class);
        $invalidationService
            ->expects(self::once())
            ->method('invalidateAfterBucketUpdate')
            ->with(99, 'tablet');

        $event = new AfterCriticalUidsUpdatedEvent(99, 'tablet', [1, 2], [2, 3]);

        $listener = new CriticalCacheInvalidationListener($invalidationService, $this->readinessService);
        $listener($event);
    }
}
