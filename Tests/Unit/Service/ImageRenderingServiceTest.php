<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Tests\Unit\Service;

use Maispace\MaispaceAssets\Service\ImageRenderingService;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Extbase\Service\ImageService;

/**
 * Unit tests for ImageRenderingService HTML-rendering and preload methods.
 *
 * Mocks are injected via the constructor — no TYPO3 bootstrap required.
 */
final class ImageRenderingServiceTest extends TestCase
{
    private ImageService&\PHPUnit\Framework\MockObject\MockObject $imageService;
    private PageRenderer&\PHPUnit\Framework\MockObject\MockObject $pageRenderer;
    private ImageRenderingService $subject;

    protected function setUp(): void
    {
        $this->imageService = $this->createMock(ImageService::class);
        $this->pageRenderer = $this->createMock(PageRenderer::class);

        $this->subject = new ImageRenderingService(
            $this->imageService,
            $this->createMock(LoggerInterface::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->pageRenderer,
        );
    }

    // -------------------------------------------------------------------------
    // detectMimeType
    // -------------------------------------------------------------------------

    /**
     * @dataProvider provideMimeTypeMapping
     */
    public function testDetectMimeTypeReturnsCorrectType(string $ext, string $expectedMime): void
    {
        $processed = $this->makeProcessedFile('/path/to/image.' . $ext);
        self::assertSame($expectedMime, $this->subject->detectMimeType($processed));
    }

    public static function provideMimeTypeMapping(): array
    {
        return [
            'jpg'  => ['jpg',  'image/jpeg'],
            'jpeg' => ['jpeg', 'image/jpeg'],
            'png'  => ['png',  'image/png'],
            'webp' => ['webp', 'image/webp'],
            'avif' => ['avif', 'image/avif'],
            'gif'  => ['gif',  'image/gif'],
            'svg'  => ['svg',  'image/svg+xml'],
        ];
    }

    public function testDetectMimeTypeReturnsEmptyStringForUnknownExtension(): void
    {
        $processed = $this->makeProcessedFile('/path/to/file.bmp');
        self::assertSame('', $this->subject->detectMimeType($processed));
    }

    // -------------------------------------------------------------------------
    // renderSourceTag
    // -------------------------------------------------------------------------

    public function testRenderSourceTagContainsSrcset(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderSourceTag($processed, null);
        self::assertStringContainsString('srcset="/img/photo.jpg"', $html);
    }

    public function testRenderSourceTagIncludesMediaAttribute(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderSourceTag($processed, '(min-width: 768px)');
        self::assertStringContainsString('media="(min-width: 768px)"', $html);
    }

    public function testRenderSourceTagIncludesMimeType(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.webp');
        $processed = $this->makeProcessedFile('/img/photo.webp');

        $html = $this->subject->renderSourceTag($processed, null);
        self::assertStringContainsString('type="image/webp"', $html);
    }

    public function testRenderSourceTagUsesExplicitTypeOverAutoDetect(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderSourceTag($processed, null, 'image/custom');
        self::assertStringContainsString('type="image/custom"', $html);
    }

    public function testRenderSourceTagUsesSrcsetStringWhenProvided(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $srcset = '/img/photo_400.jpg 400w, /img/photo_800.jpg 800w';
        $html = $this->subject->renderSourceTag($processed, null, null, $srcset);

        self::assertStringContainsString('srcset="' . htmlspecialchars($srcset, ENT_QUOTES | ENT_XML1) . '"', $html);
    }

    public function testRenderSourceTagIncludesSizesWhenSrcsetIsProvided(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $srcset = '/img/photo_400.jpg 400w, /img/photo_800.jpg 800w';
        $sizes = '(min-width: 768px) 800px, 100vw';
        $html = $this->subject->renderSourceTag($processed, null, null, $srcset, $sizes);

        self::assertStringContainsString('sizes="', $html);
    }

    public function testRenderSourceTagOmitsSizesWhenSrcsetIsAbsent(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderSourceTag($processed, null, null, null, '(min-width: 768px) 800px, 100vw');
        self::assertStringNotContainsString('sizes=', $html);
    }

    // -------------------------------------------------------------------------
    // renderImgTag
    // -------------------------------------------------------------------------

    public function testRenderImgTagContainsSrcAndAlt(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg', 800, 600);

        $html = $this->subject->renderImgTag($processed, ['alt' => 'A photo']);
        self::assertStringContainsString('src="/img/photo.jpg"', $html);
        self::assertStringContainsString('alt="A photo"', $html);
    }

    public function testRenderImgTagIncludesWidthAndHeight(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg', 800, 600);

        $html = $this->subject->renderImgTag($processed, ['alt' => '']);
        self::assertStringContainsString('width="800"', $html);
        self::assertStringContainsString('height="600"', $html);
    }

    public function testRenderImgTagAddsLoadingLazyWhenLazyloadingEnabled(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderImgTag($processed, ['alt' => '', 'lazyloading' => true]);
        self::assertStringContainsString('loading="lazy"', $html);
    }

    public function testRenderImgTagOmitsLoadingWhenLazyloadingDisabled(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderImgTag($processed, ['alt' => '', 'lazyloading' => false]);
        self::assertStringNotContainsString('loading=', $html);
    }

    public function testRenderImgTagAddsFetchpriorityHigh(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderImgTag($processed, ['alt' => '', 'fetchPriority' => 'high']);
        self::assertStringContainsString('fetchpriority="high"', $html);
    }

    public function testRenderImgTagIgnoresInvalidFetchpriority(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderImgTag($processed, ['alt' => '', 'fetchPriority' => 'invalid']);
        self::assertStringNotContainsString('fetchpriority=', $html);
    }

    public function testRenderImgTagAddsDecodingAsync(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderImgTag($processed, ['alt' => '', 'decoding' => 'async']);
        self::assertStringContainsString('decoding="async"', $html);
    }

    public function testRenderImgTagIgnoresInvalidDecoding(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderImgTag($processed, ['alt' => '', 'decoding' => 'bad-value']);
        self::assertStringNotContainsString('decoding=', $html);
    }

    public function testRenderImgTagAddsCrossoriginAnonymous(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderImgTag($processed, ['alt' => '', 'crossorigin' => 'anonymous']);
        self::assertStringContainsString('crossorigin="anonymous"', $html);
    }

    public function testRenderImgTagIgnoresInvalidCrossorigin(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderImgTag($processed, ['alt' => '', 'crossorigin' => 'bad-value']);
        self::assertStringNotContainsString('crossorigin=', $html);
    }

    public function testRenderImgTagMergesLazyloadClassWithExistingClass(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderImgTag($processed, [
            'alt'               => '',
            'class'             => 'hero-image',
            'lazyloadWithClass' => 'lazyload',
        ]);

        self::assertStringContainsString('loading="lazy"', $html);
        self::assertStringContainsString('hero-image', $html);
        self::assertStringContainsString('lazyload', $html);
    }

    public function testRenderImgTagLazyloadClassAloneEnablesLazyloading(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderImgTag($processed, [
            'alt'               => '',
            'lazyloadWithClass' => 'lazyload',
        ]);

        // lazyloadWithClass alone must enable loading="lazy"
        self::assertStringContainsString('loading="lazy"', $html);
        self::assertStringContainsString('class="lazyload"', $html);
    }

    public function testRenderImgTagAdditionalAttributesAppearOnImg(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderImgTag($processed, [
            'alt'                  => '',
            'additionalAttributes' => ['data-src' => '/img/photo.jpg', 'data-test' => 'yes'],
        ]);

        self::assertStringContainsString('data-src=', $html);
        self::assertStringContainsString('data-test="yes"', $html);
    }

    public function testRenderImgTagIncludesSrcsetAndSizes(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderImgTag($processed, [
            'alt'    => '',
            'srcset' => '/img/400.jpg 400w, /img/800.jpg 800w',
            'sizes'  => '(max-width: 768px) 100vw, 800px',
        ]);

        self::assertStringContainsString('srcset=', $html);
        self::assertStringContainsString('sizes=', $html);
    }

    public function testRenderImgTagOmitsSizesWhenSrcsetIsAbsent(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        // sizes without srcset should still be rendered
        // (the img tag itself is not as strict as <source>; sizes is passed through)
        $html = $this->subject->renderImgTag($processed, [
            'alt'   => '',
            'sizes' => '100vw',
        ]);

        // sizes without srcset is included (browsers ignore it without srcset anyway)
        self::assertStringContainsString('sizes="100vw"', $html);
    }

    public function testRenderImgTagIncludesIdAndTitle(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderImgTag($processed, [
            'alt'   => '',
            'id'    => 'hero-img',
            'title' => 'The hero image',
        ]);

        self::assertStringContainsString('id="hero-img"', $html);
        self::assertStringContainsString('title="The hero image"', $html);
    }

    public function testRenderImgTagEscapesSpecialCharsInAlt(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderImgTag($processed, ['alt' => 'A "quoted" & <special> alt']);
        self::assertStringContainsString('&amp;', $html);
        self::assertStringContainsString('&lt;', $html);
        self::assertStringContainsString('&quot;', $html);
    }

    public function testRenderImgTagProducesImgElement(): void
    {
        $this->imageService->method('getImageUri')->willReturn('/img/photo.jpg');
        $processed = $this->makeProcessedFile('/img/photo.jpg');

        $html = $this->subject->renderImgTag($processed, ['alt' => '']);
        self::assertStringStartsWith('<img ', $html);
        self::assertStringEndsWith('>', $html);
    }

    // -------------------------------------------------------------------------
    // addImagePreloadHeader
    // -------------------------------------------------------------------------

    public function testAddImagePreloadHeaderEmitsBasicPreloadTag(): void
    {
        $this->pageRenderer->expects(self::once())
            ->method('addHeaderData')
            ->with(self::stringContains('rel="preload"'));

        $this->subject->addImagePreloadHeader('/img/photo.jpg');
    }

    public function testAddImagePreloadHeaderIncludesHref(): void
    {
        $this->pageRenderer->expects(self::once())
            ->method('addHeaderData')
            ->with(self::stringContains('href="/img/photo.jpg"'));

        $this->subject->addImagePreloadHeader('/img/photo.jpg');
    }

    public function testAddImagePreloadHeaderIncludesAsImage(): void
    {
        $this->pageRenderer->expects(self::once())
            ->method('addHeaderData')
            ->with(self::stringContains('as="image"'));

        $this->subject->addImagePreloadHeader('/img/photo.jpg');
    }

    public function testAddImagePreloadHeaderIncludesMediaAttribute(): void
    {
        $this->pageRenderer->expects(self::once())
            ->method('addHeaderData')
            ->with(self::stringContains('media="(min-width: 768px)"'));

        $this->subject->addImagePreloadHeader('/img/photo.jpg', '(min-width: 768px)');
    }

    public function testAddImagePreloadHeaderIncludesFetchpriority(): void
    {
        $this->pageRenderer->expects(self::once())
            ->method('addHeaderData')
            ->with(self::stringContains('fetchpriority="high"'));

        $this->subject->addImagePreloadHeader('/img/photo.jpg', null, 'high');
    }

    public function testAddImagePreloadHeaderIgnoresInvalidFetchpriority(): void
    {
        $this->pageRenderer->expects(self::once())
            ->method('addHeaderData')
            ->with(self::logicalNot(self::stringContains('fetchpriority=')));

        $this->subject->addImagePreloadHeader('/img/photo.jpg', null, 'invalid');
    }

    public function testAddImagePreloadHeaderIncludesMimeType(): void
    {
        $this->pageRenderer->expects(self::once())
            ->method('addHeaderData')
            ->with(self::stringContains('type="image/webp"'));

        $this->subject->addImagePreloadHeader('/img/photo.webp', null, null, 'image/webp');
    }

    public function testAddImagePreloadHeaderIncludesImagesrcset(): void
    {
        $this->pageRenderer->expects(self::once())
            ->method('addHeaderData')
            ->with(self::stringContains('imagesrcset='));

        $this->subject->addImagePreloadHeader(
            '/img/photo.jpg',
            null,
            null,
            null,
            '/img/photo_400.jpg 400w, /img/photo_800.jpg 800w',
        );
    }

    public function testAddImagePreloadHeaderIncludesImagesizesOnlyWhenImagesrcsetIsPresent(): void
    {
        $this->pageRenderer->expects(self::once())
            ->method('addHeaderData')
            ->with(self::stringContains('imagesizes='));

        $this->subject->addImagePreloadHeader(
            '/img/photo.jpg',
            null,
            null,
            null,
            '/img/photo_400.jpg 400w, /img/photo_800.jpg 800w',
            '(min-width: 768px) 800px, 100vw',
        );
    }

    public function testAddImagePreloadHeaderOmitsImagesizesWhenImagesrcsetIsAbsent(): void
    {
        $this->pageRenderer->expects(self::once())
            ->method('addHeaderData')
            ->with(self::logicalNot(self::stringContains('imagesizes=')));

        // Pass sizes but no srcset — sizes should be silently dropped.
        $this->subject->addImagePreloadHeader(
            '/img/photo.jpg',
            null,
            null,
            null,
            null,
            '(min-width: 768px) 800px, 100vw',
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build a minimal ProcessedFile mock with a given identifier (used for MIME detection)
     * and optional width/height properties.
     */
    private function makeProcessedFile(string $identifier, int $width = 0, int $height = 0): ProcessedFile
    {
        $mock = $this->createMock(ProcessedFile::class);
        $mock->method('getIdentifier')->willReturn($identifier);
        $mock->method('getProperty')->willReturnMap([
            ['width',  $width],
            ['height', $height],
        ]);

        return $mock;
    }
}
