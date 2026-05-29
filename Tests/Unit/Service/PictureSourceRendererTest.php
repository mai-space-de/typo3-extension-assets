<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Service;

use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\Service\ImageVariantService;
use Maispace\MaiAssets\Service\PictureSourceRenderer;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Extbase\Service\ImageService;

final class PictureSourceRendererTest extends TestCase
{
    /** @var EventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject */
    private EventDispatcherInterface $eventDispatcher;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
    }

    public function testDefaultSourceDefinitionsMatchHeroBreakpoints(): void
    {
        $subject = $this->createRenderer();

        $definitions = $subject->defaultSourceDefinitions(1600);

        self::assertSame('(max-width: 767px)', $definitions[0]['media']);
        self::assertSame([400, 800], $definitions[0]['widths']);
        self::assertSame('(min-width: 768px)', $definitions[1]['media']);
        self::assertSame([1200, 1600], $definitions[1]['widths']);
    }

    public function testDefaultSourceDefinitionsScaleWithMaxWidth(): void
    {
        $subject = $this->createRenderer();

        $definitions = $subject->defaultSourceDefinitions(800);

        self::assertSame([600, 800], $definitions[1]['widths']);
    }

    public function testRenderDefaultSourcesOutputsAvifAndWebp(): void
    {
        $subject = $this->createRenderer($this->stubVariantService());

        $result = $subject->renderDefaultSources(
            new \stdClass(),
            '100vw',
            1600,
            false,
            null,
        );

        self::assertStringContainsString('type="image/avif"', $result);
        self::assertStringContainsString('type="image/webp"', $result);
        self::assertStringContainsString('sizes="100vw"', $result);
        self::assertStringContainsString('media="(max-width: 767px)"', $result);
        self::assertStringContainsString('media="(min-width: 768px)"', $result);
    }

    public function testCriticalDefaultSourcesHaveFetchpriority(): void
    {
        $subject = $this->createRenderer($this->stubVariantService());

        $result = $subject->renderDefaultSources(
            new \stdClass(),
            '100vw',
            1600,
            true,
            null,
        );

        self::assertStringContainsString('fetchpriority="high"', $result);
    }

    public function testCriticalDefaultSourcesRegisterEarlyHint(): void
    {
        $collector = $this->noConstructor(EarlyHintCandidateCollector::class);
        $subject = $this->createRenderer($this->stubVariantService());

        $subject->renderDefaultSources(
            new \stdClass(),
            '100vw',
            1600,
            true,
            $collector,
        );

        self::assertNotEmpty($collector->getAll());
    }

    private function createRenderer(?ImageVariantService $variantService = null): PictureSourceRenderer
    {
        return new PictureSourceRenderer($variantService ?? $this->stubVariantService());
    }

    private function stubVariantService(): ImageVariantService
    {
        $imageService = new readonly class extends ImageService {
            public function __construct() {}

            public function applyProcessingInstructions($image, array $instructions): ProcessedFile
            {
                $format = (string)($instructions['format'] ?? 'jpg');
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

                return '/f/test-' . $format . '-' . $width . '.img';
            }
        };

        $service = (new \ReflectionClass(ImageVariantService::class))->newInstanceWithoutConstructor();
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
