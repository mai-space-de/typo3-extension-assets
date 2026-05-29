<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\ViewHelpers\Image\Picture;

use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\Service\ImageVariantService;
use Maispace\MaiAssets\ViewHelpers\Image\Picture\SourceViewHelper;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContext;
use TYPO3Fluid\Fluid\Core\Variables\StandardVariableProvider;

/**
 * Tests for SourceViewHelper — verifies webp/avif source generation and fallback behaviour
 * within the picture rendering pipe.
 */
final class SourceViewHelperTest extends TestCase
{
    /** @var EventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject */
    private EventDispatcherInterface $eventDispatcher;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
    }

    /**
     * Returns empty string when not inside a PictureViewHelper context.
     */
    public function testReturnsEmptyStringWithoutPictureContext(): void
    {
        $subject = $this->createViewHelper($this->stubVariantService());

        $result = $subject->render();

        self::assertSame('', $result);
    }

    /**
     * Both avif and webp sources are generated when both formats succeed.
     */
    public function testBothFormatsGenerateSourceElements(): void
    {
        $subject = $this->createViewHelper(
            $this->stubVariantService(),
            withPictureContext: true
        );

        $result = $subject->render();

        self::assertStringContainsString('<source', $result);
        self::assertStringContainsString('type="image/avif"', $result);
        self::assertStringContainsString('type="image/webp"', $result);
    }

    /**
     * WebP source is omitted when ImageVariantService returns empty string for webp.
     */
    public function testWebpSourceOmittedWhenWebpGenerationFails(): void
    {
        $subject = $this->createViewHelper(
            $this->stubVariantService(failFormats: ['webp']),
            withPictureContext: true
        );

        $result = $subject->render();

        self::assertStringContainsString('type="image/avif"', $result);
        self::assertStringNotContainsString('type="image/webp"', $result);
    }

    /**
     * AVIF source is omitted when ImageVariantService returns empty string for avif.
     */
    public function testAvifSourceOmittedWhenAvifGenerationFails(): void
    {
        $subject = $this->createViewHelper(
            $this->stubVariantService(failFormats: ['avif']),
            withPictureContext: true
        );

        $result = $subject->render();

        self::assertStringNotContainsString('type="image/avif"', $result);
        self::assertStringContainsString('type="image/webp"', $result);
    }

    /**
     * Empty output when all formats fail.
     */
    public function testEmptyOutputWhenAllFormatsFail(): void
    {
        $subject = $this->createViewHelper(
            $this->stubVariantService(failFormats: ['avif', 'webp']),
            withPictureContext: true
        );

        $result = $subject->render();

        self::assertSame('', $result);
    }

    /**
     * Empty output when srcset array is empty.
     */
    public function testEmptyOutputWhenSrcsetIsEmpty(): void
    {
        $subject = $this->createViewHelper(
            $this->stubVariantService(),
            withPictureContext: true,
            srcset: []
        );

        $result = $subject->render();

        self::assertSame('', $result);
    }

    /**
     * media query attribute is present on <source> elements.
     */
    public function testMediaQueryIsRenderedOnSource(): void
    {
        $subject = $this->createViewHelper(
            $this->stubVariantService(),
            withPictureContext: true,
            media: '(max-width: 767px)'
        );

        $result = $subject->render();

        self::assertStringContainsString('media="(max-width: 767px)"', $result);
    }

    /**
     * sizes attribute is rendered on <source> when provided.
     */
    public function testSizesAttributeIsRenderedWhenProvided(): void
    {
        $subject = $this->createViewHelper(
            $this->stubVariantService(),
            withPictureContext: true,
            sizes: '(max-width: 767px) 100vw, 50vw'
        );

        $result = $subject->render();

        self::assertStringContainsString('sizes="(max-width: 767px) 100vw, 50vw"', $result);
    }

    /**
     * sizes attribute is omitted when not provided.
     */
    public function testSizesAttributeIsOmittedWhenEmpty(): void
    {
        $subject = $this->createViewHelper(
            $this->stubVariantService(),
            withPictureContext: true
        );

        $result = $subject->render();

        self::assertStringNotContainsString('sizes=', $result);
    }

    /**
     * srcset contains proper width descriptors (e.g. 400w, 800w).
     */
    public function testSrcsetContainsWidthDescriptors(): void
    {
        $subject = $this->createViewHelper(
            $this->stubVariantService(),
            withPictureContext: true,
            srcset: [400, 800]
        );

        $result = $subject->render();

        self::assertStringContainsString('400w', $result);
        self::assertStringContainsString('800w', $result);
    }

    /**
     * Critical image with avif source registers an early hint candidate.
     */
    public function testCriticalImageRegistersEarlyHintForAvif(): void
    {
        $earlyHintCollector = $this->noConstructor(EarlyHintCandidateCollector::class);

        $subject = $this->createViewHelper(
            $this->stubVariantService(),
            withPictureContext: true,
            isCritical: true,
            earlyHintCollector: $earlyHintCollector
        );

        $subject->render();

        self::assertCount(1, $earlyHintCollector->getAll());
    }

    /**
     * Non-critical image does not register early hints.
     */
    public function testNonCriticalImageDoesNotRegisterEarlyHint(): void
    {
        $earlyHintCollector = $this->noConstructor(EarlyHintCandidateCollector::class);

        $subject = $this->createViewHelper(
            $this->stubVariantService(),
            withPictureContext: true,
            isCritical: false,
            earlyHintCollector: $earlyHintCollector
        );

        $subject->render();

        self::assertCount(0, $earlyHintCollector->getAll());
    }

    /**
     * Critical image has fetchpriority="high" on <source> elements.
     */
    public function testCriticalImageHasFetchpriorityOnSource(): void
    {
        $subject = $this->createViewHelper(
            $this->stubVariantService(),
            withPictureContext: true,
            isCritical: true,
        );

        $result = $subject->render();

        self::assertStringContainsString('fetchpriority="high"', $result);
        self::assertStringContainsString('<source', $result);
    }

    /**
     * Non-critical image does NOT have fetchpriority on <source> elements.
     */
    public function testNonCriticalImageDoesNotHaveFetchpriorityOnSource(): void
    {
        $subject = $this->createViewHelper(
            $this->stubVariantService(),
            withPictureContext: true,
            isCritical: false,
        );

        $result = $subject->render();

        self::assertStringNotContainsString('fetchpriority', $result);
        self::assertStringContainsString('<source', $result);
    }

    /**
     * WebP failure with critical image: early hint still registered for avif.
     */
    public function testWebpFailureWithCriticalStillRegistersAvifEarlyHint(): void
    {
        $earlyHintCollector = $this->noConstructor(EarlyHintCandidateCollector::class);

        $subject = $this->createViewHelper(
            $this->stubVariantService(failFormats: ['webp']),
            withPictureContext: true,
            isCritical: true,
            earlyHintCollector: $earlyHintCollector
        );

        $result = $subject->render();

        self::assertStringNotContainsString('type="image/webp"', $result);
        self::assertCount(1, $earlyHintCollector->getAll());
    }

    // ── helpers ──────────────────────────────────────────────────────────────────

    private function createViewHelper(
        ImageVariantService $variantService,
        bool $withPictureContext = false,
        string $media = '(min-width: 768px)',
        array $srcset = [400, 800],
        string $sizes = '',
        array $formats = ['avif', 'webp'],
        bool $isCritical = false,
        ?EarlyHintCandidateCollector $earlyHintCollector = null,
    ): SourceViewHelper {
        $vh = new SourceViewHelper($variantService);

        $ctx = $this->createRenderingContext();

        if ($withPictureContext) {
            $vp = $ctx->getVariableProvider();
            $vp->add('__pictureFileReference', new \stdClass());
            $vp->add('__pictureIsCritical', $isCritical);
            $vp->add('__pictureEarlyHintCollector', $earlyHintCollector);
        }

        $vh->setRenderingContext($ctx);
        $vh->setArguments([
            'media'   => $media,
            'srcset'  => $srcset,
            'sizes'   => $sizes,
            'formats' => $formats,
            'quality' => 85,
            'width'   => 0,
            'height'  => 0,
        ]);

        return $vh;
    }

    private function createRenderingContext(): RenderingContext
    {
        return new class extends RenderingContext {
            public function __construct()
            {
                $this->variableProvider = new StandardVariableProvider();
            }

            public function getRequest(): \Psr\Http\Message\ServerRequestInterface
            {
                return $GLOBALS['TYPO3_REQUEST'];
            }
        };
    }

    /**
     * Stub ImageVariantService returning predictable URLs or empty for failed formats.
     *
     * @param string[] $failFormats Formats that should return empty string (simulating failure)
     */
    private function stubVariantService(array $failFormats = []): ImageVariantService
    {
        $imageService = new readonly class($failFormats) extends ImageService {
            public function __construct(private readonly array $failFormats) {}

            public function applyProcessingInstructions($image, array $instructions): ProcessedFile
            {
                $format = $instructions['format'] ?? '';
                if (in_array($format, $this->failFormats, true)) {
                    throw new \RuntimeException('Format failed: ' . $format);
                }
                return new class extends ProcessedFile {
                };
            }

            public function getImageUri($file, bool $absolute = false): string
            {
                return '/f/test.img';
            }
        };

        $service = $this->noConstructor(ImageVariantService::class);
        $this->setProp($service, 'imageService', $imageService);
        $this->setProp($service, 'eventDispatcher', $this->eventDispatcher);
        return $service;
    }

    /** @template T of object */
    private function noConstructor(string $class): object
    {
        /** @var T */
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function setProp(object $target, string $property, mixed $value): void
    {
        $prop = new \ReflectionProperty($target::class, $property);
        $prop->setAccessible(true);
        $prop->setValue($target, $value);
    }
}
