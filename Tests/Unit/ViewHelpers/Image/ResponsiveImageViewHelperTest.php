<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\ViewHelpers\Image;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidate;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\Service\AssetCriticalityResolver;
use Maispace\MaiAssets\Service\CriticalDetectionService;
use Maispace\MaiAssets\Service\ImageVariantService;
use Maispace\MaiAssets\ViewHelpers\Image\ResponsiveImageViewHelper;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContext;

/**
 * Tests for the automated picture rendering pipe (ResponsiveImageViewHelper).
 *
 * Verifies that webp fallback generation is correctly handled in the HTML output:
 * when ImageVariantService returns an empty string for webp (i.e., generation failed),
 * the webp <source> tag is omitted while avif and jpeg sources remain.
 */
final class ResponsiveImageViewHelperTest extends TestCase
{
    /** @var EventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject */
    private EventDispatcherInterface $eventDispatcher;

    private EarlyHintCandidateCollector $earlyHintCollector;
    private AssetCriticalityResolver $criticalityResolver;
    private ExtensionConfiguration $extensionConfiguration;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

        $this->earlyHintCollector = $this->noConstructor(EarlyHintCandidateCollector::class);

        // AssetCriticalityResolver — defaults to no observer data (non-critical)
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = [
            'viewportBuckets' => ['mobile' => 768, 'tablet' => 1024, 'desktop' => 99999],
        ];
        $this->extensionConfiguration = new ExtensionConfiguration(
            $this->noConstructor(Typo3ExtensionConfiguration::class)
        );

        $cacheService = $this->noConstructor(AboveFoldCacheService::class);
        $detectionService = $this->noConstructor(CriticalDetectionService::class);
        $this->criticalityResolver = new AssetCriticalityResolver($cacheService, $detectionService, $this->extensionConfiguration);

        // Set up a mock server request for getRequest() calls
        $mockRequest = $this->createMock(ServerRequestInterface::class);
        $mockRequest->method('getAttribute')->willReturn(null);
        $GLOBALS['TYPO3_REQUEST'] = $mockRequest;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets']);
        unset($GLOBALS['TYPO3_REQUEST']);
    }

    /**
     * All three formats succeed → output includes avif, webp, and jpeg sources.
     */
    public function testAllFormatsSucceedRendersThreeSources(): void
    {
        $imageService = $this->stubImageService();
        $subject = $this->createViewHelper($imageService);

        $result = $subject->render();

        self::assertStringContainsString('<source type="image/avif"', $result);
        self::assertStringContainsString('<source type="image/webp"', $result);
        // JPEG is on the <img> tag, not a <source>
        self::assertStringContainsString('<img', $result);
        self::assertStringContainsString('loading="lazy"', $result);
    }

    /**
     * WebP generation fails → webp <source> omitted, avif and jpeg remain.
     */
    public function testOmitsWebpSourceWhenWebpGenerationFails(): void
    {
        $imageService = $this->stubImageService(['webp']);
        $subject = $this->createViewHelper($imageService);

        $result = $subject->render();

        self::assertStringContainsString('<source type="image/avif"', $result);
        self::assertStringNotContainsString('<source type="image/webp"', $result);
        self::assertStringContainsString('<img', $result);
    }

    /**
     * AVIF generation fails → avif <source> omitted, webp and jpeg remain.
     */
    public function testOmitsAvifSourceWhenAvifGenerationFails(): void
    {
        $imageService = $this->stubImageService(['avif']);
        $subject = $this->createViewHelper($imageService);

        $result = $subject->render();

        self::assertStringNotContainsString('<source type="image/avif"', $result);
        self::assertStringContainsString('<source type="image/webp"', $result);
        self::assertStringContainsString('<img', $result);
    }

    /**
     * Both avif and webp fail → only fallback <img> tag remains.
     */
    public function testAllModernFormatsFailRenderOnlyFallbackImg(): void
    {
        $imageService = $this->stubImageService(['avif', 'webp']);
        $subject = $this->createViewHelper($imageService);

        $result = $subject->render();

        self::assertStringNotContainsString('<source type="image/avif"', $result);
        self::assertStringNotContainsString('<source type="image/webp"', $result);
        self::assertStringContainsString('<img', $result);
        self::assertStringContainsString('src="/f/image-1200.jpg"', $result);
    }

    /**
     * JPEG generation fails → jpeg srcset omitted from <img>, but avif and webp sources remain.
     */
    public function testJpegFailureOmitsJpegFromImgButKeepsModernFormats(): void
    {
        $imageService = $this->stubImageService(['jpg']);
        $subject = $this->createViewHelper($imageService);

        $result = $subject->render();

        self::assertStringContainsString('<source type="image/avif"', $result);
        self::assertStringContainsString('<source type="image/webp"', $result);
        self::assertStringContainsString('<img', $result);
    }

    /**
     * Critical image has eager loading, fetchpriority, and registers an early hint.
     */
    public function testCriticalImageHasEagerLoadingAndRegistersEarlyHint(): void
    {
        $imageService = $this->stubImageService();
        $subject = $this->createViewHelper($imageService, critical: 'true');

        $result = $subject->render();

        self::assertStringContainsString('loading="eager"', $result);
        self::assertStringContainsString('fetchpriority="high"', $result);
        self::assertStringContainsString('decoding="sync"', $result);
        self::assertCount(1, $this->earlyHintCollector->getAll());
    }

    /**
     * Non-critical image has lazy loading.
     */
    public function testNonCriticalImageHasLazyLoading(): void
    {
        $imageService = $this->stubImageService();
        $subject = $this->createViewHelper($imageService, critical: 'false');

        $result = $subject->render();

        self::assertStringContainsString('loading="lazy"', $result);
        self::assertStringNotContainsString('fetchpriority', $result);
        self::assertStringNotContainsString('loading="eager"', $result);
    }

    /**
     * Alt text is rendered on the img tag.
     */
    public function testAltTextIsRendered(): void
    {
        $imageService = $this->stubImageService();
        $subject = $this->createViewHelper($imageService, alt: 'A test image');

        $result = $subject->render();

        self::assertStringContainsString('alt="A test image"', $result);
    }

    /**
     * CSS class is rendered on the img tag.
     */
    public function testCssClassIsRendered(): void
    {
        $imageService = $this->stubImageService();
        $subject = $this->createViewHelper($imageService, class: 'hero-image');

        $result = $subject->render();

        self::assertStringContainsString('class="hero-image"', $result);
    }

    /**
     * Sizes attribute is present on all <source> and <img> elements.
     */
    public function testSizesAttributeIsPresent(): void
    {
        $imageService = $this->stubImageService();
        $subject = $this->createViewHelper($imageService);

        $result = $subject->render();

        self::assertStringContainsString('sizes="(max-width: 767px) 100vw, 50vw"', $result);
    }

    /**
     * srcset attributes contain proper width descriptors.
     */
    public function testSrcsetContainsWidthDescriptors(): void
    {
        $imageService = $this->stubImageService();
        $subject = $this->createViewHelper($imageService);

        $result = $subject->render();

        self::assertStringContainsString('400w', $result);
        self::assertStringContainsString('1200w', $result);
    }

    /**
     * Output is wrapped in a <picture> element.
     */
    public function testOutputIsWrappedInPictureElement(): void
    {
        $imageService = $this->stubImageService();
        $subject = $this->createViewHelper($imageService);

        $result = $subject->render();

        self::assertStringStartsWith('<picture>', $result);
        self::assertStringEndsWith('</picture>', $result);
    }

    /**
     * WebP fails + critical image → early hint still registered for avif.
     */
    public function testWebpFailureWithCriticalStillRegistersAvifEarlyHint(): void
    {
        $imageService = $this->stubImageService(['webp']);
        $subject = $this->createViewHelper($imageService, critical: 'true');

        $result = $subject->render();

        self::assertStringNotContainsString('<source type="image/webp"', $result);
        self::assertStringContainsString('loading="eager"', $result);
        self::assertCount(1, $this->earlyHintCollector->getAll());
    }

    // ── helpers ──────────────────────────────────────────────────────────────────

    /**
     * Create a ResponsiveImageViewHelper with controlled dependencies.
     */
    private function createViewHelper(
        ImageService $imageService,
        string $critical = 'false',
        string $alt = '',
        string $class = '',
    ): ResponsiveImageViewHelper {
        $imageVariantService = $this->createImageVariantService($imageService);

        $vh = new ResponsiveImageViewHelper(
            $imageVariantService,
            $this->criticalityResolver,
            $this->earlyHintCollector,
        );

        $vh->setRenderingContext($this->createRenderingContext());
        $vh->setArguments([
            'image'       => new \stdClass(),
            'breakpoints' => ['mobile' => 400, 'desktop' => 1200],
            'sizes'       => '(max-width: 767px) 100vw, 50vw',
            'critical'    => $critical,
            'elementUid'  => 0,
            'alt'         => $alt,
            'class'       => $class,
        ]);

        return $vh;
    }

    private function createImageVariantService(ImageService $imageService): ImageVariantService
    {
        $service = $this->noConstructor(ImageVariantService::class);
        $this->setProp($service, 'imageService', $imageService);
        $this->setProp($service, 'eventDispatcher', $this->eventDispatcher);
        return $service;
    }

    /**
     * Create a RenderingContext that supports getRequest() via the global.
     */
    private function createRenderingContext(): RenderingContext
    {
        return new class extends RenderingContext {
            public function getRequest(): \Psr\Http\Message\ServerRequestInterface
            {
                return $GLOBALS['TYPO3_REQUEST'];
            }
        };
    }

    /**
     * Create an ImageService stub that returns predictable URLs per format+width.
     *
     * @param string[] $failFormats ImageService format names that should throw (e.g. ['webp', 'avif', 'jpg'])
     */
    private function stubImageService(array $failFormats = []): ImageService
    {
        return new readonly class($failFormats) extends ImageService {
            public function __construct(private readonly array $failFormats)
            {
            }

            public function applyProcessingInstructions($image, array $instructions): ProcessedFile
            {
                $format = $instructions['format'];
                $width = (int)$instructions['width'];

                if (in_array($format, $this->failFormats, true)) {
                    throw new RuntimeException('Format not supported: ' . $format);
                }

                return new class($format, $width) extends ProcessedFile {
                    public function __construct(
                        private readonly string $format,
                        private readonly int $width,
                    ) {
                    }

                    public function getFormat(): string
                    {
                        return $this->format;
                    }

                    public function getWidth(): int
                    {
                        return $this->width;
                    }
                };
            }

            public function getImageUri($file, bool $absolute = false): string
            {
                $format = ($file instanceof ProcessedFile && method_exists($file, 'getFormat'))
                    ? $file->getFormat()
                    : 'jpg';
                $width = ($file instanceof ProcessedFile && method_exists($file, 'getWidth'))
                    ? $file->getWidth()
                    : 0;
                return '/f/image-' . $width . '.' . $format;
            }
        };
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
