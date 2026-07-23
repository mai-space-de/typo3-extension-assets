<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\ViewHelpers\Image;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\Service\AssetCriticalityResolver;
use Maispace\MaiAssets\Service\CriticalDetectionService;
use Maispace\MaiAssets\Service\ImageVariantService;
use Maispace\MaiAssets\Service\PictureSourceRenderer;
use Maispace\MaiAssets\ViewHelpers\Image\PictureViewHelper;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContext;
use TYPO3Fluid\Fluid\Core\Variables\StandardVariableProvider;

/**
 * Tests for PictureViewHelper — verifies the <picture> wrapper, fallback <img>,
 * criticality attributes, and that child sources are rendered via renderChildren().
 */
final class PictureViewHelperTest extends TestCase
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
        $mockRequest = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $mockRequest->method('getAttribute')->willReturn(null);
        $GLOBALS['TYPO3_REQUEST'] = $mockRequest;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets']);
        unset($GLOBALS['TYPO3_REQUEST']);
    }

    /**
     * Output is wrapped in a <picture> element.
     */
    public function testOutputIsWrappedInPictureElement(): void
    {
        $subject = $this->createViewHelper($this->stubImageService());

        $result = $subject->render();

        self::assertStringContainsString('<picture>', $result);
        self::assertStringContainsString('</picture>', $result);
    }

    /**
     * Fallback <img> tag is always present in the output.
     */
    public function testFallbackImgIsAlwaysPresent(): void
    {
        $subject = $this->createViewHelper($this->stubImageService());

        $result = $subject->render();

        self::assertStringContainsString('<img ', $result);
        self::assertStringContainsString('src="/f/fallback.jpg"', $result);
    }

    /**
     * Non-critical image has lazy loading.
     */
    public function testNonCriticalImageHasLazyLoading(): void
    {
        $subject = $this->createViewHelper($this->stubImageService(), critical: 'false');

        $result = $subject->render();

        self::assertStringContainsString('loading="lazy"', $result);
        self::assertStringNotContainsString('fetchpriority', $result);
        self::assertStringNotContainsString('loading="eager"', $result);
    }

    /**
     * Critical image has eager loading, fetchpriority="high" and decoding="sync".
     */
    public function testCriticalImageHasEagerLoadingAttributes(): void
    {
        $subject = $this->createViewHelper($this->stubImageService(), critical: 'true');

        $result = $subject->render();

        self::assertStringContainsString('loading="eager"', $result);
        self::assertStringContainsString('fetchpriority="high"', $result);
        self::assertStringContainsString('decoding="sync"', $result);
    }

    /**
     * Alt text is rendered on the fallback img tag.
     */
    public function testAltTextIsRenderedOnImg(): void
    {
        $subject = $this->createViewHelper($this->stubImageService(), alt: 'Hero image');

        $result = $subject->render();

        self::assertStringContainsString('alt="Hero image"', $result);
    }

    /**
     * Processed image dimensions are rendered as width/height on the fallback img (perf-08).
     */
    public function testProcessedDimensionsAreRenderedOnImg(): void
    {
        $subject = $this->createViewHelper($this->stubImageServiceWithDimensions(1200, 675), width: 1200);

        $result = $subject->render();

        self::assertStringContainsString('width="1200"', $result);
        self::assertStringContainsString('height="675"', $result);
    }

    /**
     * Explicit height argument is preserved on the fallback img.
     */
    public function testExplicitHeightIsRenderedOnImg(): void
    {
        $subject = $this->createViewHelper($this->stubImageServiceWithDimensions(800, 450), width: 800, height: 450);

        $result = $subject->render();

        self::assertStringContainsString('width="800"', $result);
        self::assertStringContainsString('height="450"', $result);
    }

    /**
     * CSS class is rendered on the fallback img tag.
     */
    public function testCssClassIsRenderedOnImg(): void
    {
        $subject = $this->createViewHelper($this->stubImageService(), class: 'picture-img');

        $result = $subject->render();

        self::assertStringContainsString('class="picture-img"', $result);
    }

    /**
     * crossorigin attribute is rendered when provided.
     */
    public function testCrossoriginAttributeIsRendered(): void
    {
        $subject = $this->createViewHelper($this->stubImageService(), crossorigin: 'anonymous');

        $result = $subject->render();

        self::assertStringContainsString('crossorigin="anonymous"', $result);
    }

    /**
     * crossorigin attribute is omitted when empty.
     */
    public function testCrossoriginAttributeIsOmittedWhenEmpty(): void
    {
        $subject = $this->createViewHelper($this->stubImageService());

        $result = $subject->render();

        self::assertStringNotContainsString('crossorigin', $result);
    }

    /**
     * When ImageService throws, fallback <img> has empty src.
     */
    public function testFallbackImgHasEmptySrcWhenImageServiceThrows(): void
    {
        $imageService = new readonly class extends ImageService {
            public function __construct() {}

            public function applyProcessingInstructions($image, array $instructions): ProcessedFile
            {
                throw new RuntimeException('Processing failed');
            }

            public function getImageUri($file, bool $absolute = false): string
            {
                return '';
            }
        };

        $subject = $this->createViewHelper($imageService);

        $result = $subject->render();

        self::assertStringContainsString('<img ', $result);
        self::assertStringContainsString('src=""', $result);
    }

    /**
     * Children rendered by renderChildren() are included between <picture> and <img>.
     */
    public function testChildrenAreIncludedInOutput(): void
    {
        $pictureSourceRenderer = $this->createMock(PictureSourceRenderer::class);
        $pictureSourceRenderer->expects(self::never())->method('renderDefaultSources');

        $subject = $this->createViewHelperWithChildSources(
            $this->stubImageService(),
            '<source type="image/avif" srcset="/f/test.avif 800w">' . "\n",
            $pictureSourceRenderer,
        );

        $result = $subject->render();

        self::assertStringContainsString('<source type="image/avif"', $result);
    }

    /**
     * When no child sources are defined, default AVIF/WebP srcsets are rendered.
     */
    public function testDefaultSourcesRenderedWhenNoChildren(): void
    {
        $pictureSourceRenderer = $this->createMock(PictureSourceRenderer::class);
        $pictureSourceRenderer
            ->expects(self::once())
            ->method('renderDefaultSources')
            ->willReturn('<source type="image/avif" srcset="/f/hero.avif 800w">' . "\n");

        $subject = $this->createViewHelper($this->stubImageService(), pictureSourceRenderer: $pictureSourceRenderer);

        $result = $subject->render();

        self::assertStringContainsString('<source type="image/avif"', $result);
    }

    /**
     * Picture ViewHelper scoped variables are cleaned up after render.
     */
    public function testVariableProviderIsCleanedUpAfterRender(): void
    {
        $renderingContext = $this->createRenderingContext();
        $subject = $this->createViewHelper($this->stubImageService(), renderingContext: $renderingContext);

        $subject->render();

        $container = $renderingContext->getViewHelperVariableContainer();
        self::assertFalse($container->exists(PictureViewHelper::class, 'fileReference'));
        self::assertFalse($container->exists(PictureViewHelper::class, 'isCritical'));
        self::assertFalse($container->exists(PictureViewHelper::class, 'earlyHintCollector'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────

    private function createViewHelperWithChildSources(
        ImageService $imageService,
        string $childSources,
        ?PictureSourceRenderer $pictureSourceRenderer = null,
    ): PictureViewHelper {
        $vh = new class(
            $pictureSourceRenderer ?? new PictureSourceRenderer($this->createImageVariantService($imageService)),
            $imageService,
            $this->criticalityResolver,
            $this->earlyHintCollector,
        ) extends PictureViewHelper {
            public string $forcedChildSources = '';

            protected function renderChildSources(): string
            {
                return $this->forcedChildSources;
            }
        };

        $vh->forcedChildSources = $childSources;
        $ctx = $this->createRenderingContext();
        $vh->setRenderingContext($ctx);
        $vh->setArguments([
            'image'         => new \stdClass(),
            'alt'           => '',
            'width'         => 0,
            'height'        => 0,
            'critical'      => 'false',
            'elementUid'    => 0,
            'quality'       => 85,
            'fileExtension' => '',
            'crossorigin'   => '',
            'class'         => '',
            'sizes'         => '100vw',
        ]);

        return $vh;
    }

    private function createViewHelper(
        ImageService $imageService,
        string $critical = 'false',
        string $alt = '',
        string $class = '',
        string $crossorigin = '',
        string $childrenOutput = '',
        ?RenderingContext $renderingContext = null,
        ?PictureSourceRenderer $pictureSourceRenderer = null,
        int $width = 0,
        int $height = 0,
    ): PictureViewHelper {
        if ($pictureSourceRenderer === null) {
            $pictureSourceRenderer = $this->createMock(PictureSourceRenderer::class);
            $pictureSourceRenderer->method('renderDefaultSources')->willReturn('');
        }

        $vh = new PictureViewHelper(
            $pictureSourceRenderer,
            $imageService,
            $this->criticalityResolver,
            $this->earlyHintCollector,
        );

        $ctx = $renderingContext ?? $this->createRenderingContext($childrenOutput);
        $vh->setRenderingContext($ctx);
        $vh->setArguments([
            'image'         => new \stdClass(),
            'alt'           => $alt,
            'width'         => $width,
            'height'        => $height,
            'critical'      => $critical,
            'elementUid'    => 0,
            'quality'       => 85,
            'fileExtension' => '',
            'crossorigin'   => $crossorigin,
            'class'         => $class,
            'sizes'         => '100vw',
        ]);

        return $vh;
    }

    private function stubImageServiceWithDimensions(int $width, int $height): ImageService
    {
        return new readonly class($width, $height) extends ImageService {
            public function __construct(
                private readonly int $width,
                private readonly int $height,
            ) {}

            public function applyProcessingInstructions($image, array $instructions): ProcessedFile
            {
                return new class($this->width, $this->height) extends ProcessedFile {
                    public function __construct(
                        private readonly int $width,
                        private readonly int $height,
                    ) {
                    }

                    public function getProperty(string $key): mixed
                    {
                        return match ($key) {
                            'width' => $this->width,
                            'height' => $this->height,
                            default => null,
                        };
                    }
                };
            }

            public function getImageUri($file, bool $absolute = false): string
            {
                return '/f/fallback.jpg';
            }
        };
    }

    private function createImageVariantService(ImageService $imageService): ImageVariantService
    {
        $service = $this->noConstructor(ImageVariantService::class);
        $this->setProp($service, 'imageService', $imageService);
        $this->setProp($service, 'eventDispatcher', $this->eventDispatcher);
        return $service;
    }

    private function createRenderingContext(string $childrenOutput = ''): RenderingContext
    {
        return new class($childrenOutput) extends RenderingContext {
            private string $childrenOutput;

            public function __construct(string $childrenOutput)
            {
                $this->childrenOutput = $childrenOutput;
                $this->setVariableProvider($this->createPictureVariableProvider());
                $this->setViewHelperVariableContainer(new \TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperVariableContainer());
            }

            public function hasAttribute(string $className): bool
            {
                return $className === \Psr\Http\Message\ServerRequestInterface::class
                    && isset($GLOBALS['TYPO3_REQUEST']);
            }

            public function getAttribute(string $className): object
            {
                if ($className === \Psr\Http\Message\ServerRequestInterface::class) {
                    return $GLOBALS['TYPO3_REQUEST'];
                }

                throw new \RuntimeException('Attribute not set: ' . $className, 1774260001);
            }

            private function createPictureVariableProvider(): StandardVariableProvider
            {
                return new class extends StandardVariableProvider {
                    /** @var array<string, mixed> */
                    private array $storage = [];

                    public function add(string $identifier, mixed $value): void
                    {
                        $this->storage[$identifier] = $value;
                    }

                    public function get(string $identifier): mixed
                    {
                        return $this->storage[$identifier];
                    }

                    public function exists(string $identifier): bool
                    {
                        return array_key_exists($identifier, $this->storage);
                    }

                    public function remove(string $identifier): void
                    {
                        unset($this->storage[$identifier]);
                    }
                };
            }
        };
    }

    /**
     * Returns an ImageService stub that produces predictable fallback URLs.
     */
    private function stubImageService(): ImageService
    {
        return new readonly class extends ImageService {
            public function __construct() {}

            public function applyProcessingInstructions($image, array $instructions): ProcessedFile
            {
                $format = (string)($instructions['fileExtension'] ?? $instructions['format'] ?? 'jpg');
                $width = (int)($instructions['width'] ?? 0);

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

                    public function getProperty(string $key): mixed
                    {
                        return match ($key) {
                            'width' => $this->width,
                            'height' => 0,
                            default => null,
                        };
                    }
                };
            }

            public function getImageUri($file, bool $absolute = false): string
            {
                return '/f/fallback.jpg';
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
