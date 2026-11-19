<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\EarlyHints;

use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidate;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

final class EarlyHintCandidateTest extends TestCase
{
    public function testSimpleLinkHeader(): void
    {
        self::assertSame(
            '</a.css>; rel=preload; as=style',
            (new EarlyHintCandidate('/a.css', 'preload', 'style'))->toLinkHeaderValue(),
        );
    }

    public function testResponsiveImagePreloadQuotesItsParameters(): void
    {
        $candidate = new EarlyHintCandidate(
            href: '/i-400.avif',
            rel: 'preload',
            as: 'image',
            type: 'image/avif',
            imageSrcset: '/i-400.avif 400w, /i-800.avif 800w',
            imageSizes: '(max-width: 767px) 100vw, 640px',
            media: '(max-width: 767px)',
        );

        // Quoted, so the commas of srcset/sizes do not split the Link header.
        self::assertSame(
            '</i-400.avif>; rel=preload; as=image; type=image/avif'
            . '; imagesrcset="/i-400.avif 400w, /i-800.avif 800w"'
            . '; imagesizes="(max-width: 767px) 100vw, 640px"'
            . '; media="(max-width: 767px)"',
            $candidate->toLinkHeaderValue(),
        );
    }

    public function testQuotesInsideValuesAreEscaped(): void
    {
        self::assertStringContainsString(
            'media="a\"b"',
            (new EarlyHintCandidate('/x', 'preload', media: "a\"b\r\n"))->toLinkHeaderValue(),
        );
    }

    public function testSameImageForDifferentMediaIsKeptTwice(): void
    {
        $collector = new EarlyHintCandidateCollector();
        $collector->add(new EarlyHintCandidate('/i.avif', 'preload', 'image', media: '(max-width: 767px)'));
        $collector->add(new EarlyHintCandidate('/i.avif', 'preload', 'image', media: '(min-width: 768px)'));

        self::assertCount(2, $collector->getAll());
    }

    public function testManifestKeepsResponsiveAttributes(): void
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn(new VariableFrontend('mai_assets_early_hints', new TransientMemoryBackend()));
        $service = new EarlyHintCacheService($cacheManager);
        $collector = new EarlyHintCandidateCollector();
        $original = new EarlyHintCandidate('/i.avif', 'preload', 'image', 'image/avif', '', '/i.avif 400w', '100vw', '(max-width: 767px)');
        $collector->add($original);

        $service->store(3, 0, $collector);

        self::assertEquals([$original], $service->load(3, 0));
    }
}
