<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\ViewHelpers\Image;

use Maispace\MaiAssets\Cache\AboveFoldCacheService;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\Service\AssetCriticalityResolver;
use Maispace\MaiAssets\Service\CriticalDetectionService;
use Maispace\MaiAssets\Service\ImageVariantService;
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
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets']);
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
        $subject = $this->createViewHelper(
            $this->stubImageService(),
            childrenOutput: '<source type="image/avif" srcset="/f/test.avif 800w">' . "\n"
        );

        $result = $subject->render();

        self::assertStringContainsString('<source type="image/avif"', $result);
    }

    /**
     * __pictureFileReference is cleaned up from variable provider after render.
     */
    public function testVariableProviderIsCleanedUpAfterRender(): void
    {
        $renderingContext = $this->createRenderingContext();
        $subject = $this->createViewHelper($this->stubImageService(), renderingContext: $renderingContext);

        $subject->render();

        self::assertFalse($renderingContext->getVariableProvider()->exists('__pictureFileReference'));
        self::assertFalse($renderingContext->getVariableProvider()->exists('__pictureIsCritical'));
        self::assertFalse($renderingContext->getVariableProvider()->exists('__pictureEarlyHintCollector'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────

    private function createViewHelper(
        ImageService $imageService,
        string $critical = 'false',
        string $alt = '',
        string $class = '',
        string $crossorigin = '',
        string $childrenOutput = '',
        ?RenderingContext $renderingContext = null,
    ): PictureViewHelper {
        $imageVariantService = $this->createImageVariantService($imageService);

        $vh = new PictureViewHelper(
            $imageVariantService,
            $imageService,
            $this->criticalityResolver,
            $this->earlyHintCollector,
        );

        $ctx = $renderingContext ?? $this->createRenderingContext($childrenOutput);
        $vh->setRenderingContext($ctx);
        $vh->setArguments([
            'image'         => new \stdClass(),
            'alt'           => $alt,
            'width'         => 0,
            'height'        => 0,
            'critical'      => $critical,
            'elementUid'    => 0,
            'quality'       => 85,
            'fileExtension' => '',
            'crossorigin'   => $crossorigin,
            'class'         => $class,
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

    private function createRenderingContext(string $childrenOutput = ''): RenderingContext
    {
        return new class($childrenOutput) extends RenderingContext {
            private string $childrenOutput;

            public function __construct(string $childrenOutput)
            {
                $this->childrenOutput = $childrenOutput;
                $this->variableProvider = new StandardVariableProvider();
            }

            public function getRequest(): \Psr\Http\Message\ServerRequestInterface
            {
                return $GLOBALS['TYPO3_REQUEST'];
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
                return new class extends ProcessedFile {
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
