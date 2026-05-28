<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Service;

use Maispace\MaiAssets\Event\AfterImageProcessedEvent;
use Maispace\MaiAssets\Event\BeforeImageProcessingEvent;
use Maispace\MaiAssets\Service\ImageVariantService;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Extbase\Service\ImageService;

final class ImageVariantServiceTest extends TestCase
{
    private EventDispatcherInterface $eventDispatcher;
    private object $fileReference;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->fileReference = new \stdClass();
    }

    public function testAllFormatsSucceedProducesNonEmptyVariants(): void
    {
        $breakpoints = ['mobile' => 400, 'desktop' => 1200];
        $imageService = $this->stubAllSucceed();
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $subject = new ImageVariantService($imageService, $this->eventDispatcher);
        $result = $subject->processVariants($this->fileReference, $breakpoints);

        self::assertArrayHasKey('mobile', $result);
        self::assertArrayHasKey('desktop', $result);
        self::assertSame(400, $result['mobile']['width']);
        self::assertSame(1200, $result['desktop']['width']);
        foreach (['mobile', 'desktop'] as $bucket) {
            self::assertNotEmpty($result[$bucket]['avif']);
            self::assertNotEmpty($result[$bucket]['webp']);
            self::assertNotEmpty($result[$bucket]['jpeg']);
        }
    }

    public function testWebpFailureProducesEmptyWebpButKeepsAvifAndJpeg(): void
    {
        $breakpoints = ['mobile' => 400, 'desktop' => 1200];
        $imageService = $this->stubFormatFails('webp');
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $subject = new ImageVariantService($imageService, $this->eventDispatcher);
        $result = $subject->processVariants($this->fileReference, $breakpoints);

        self::assertSame(400, $result['mobile']['width']);
        self::assertSame(1200, $result['desktop']['width']);
        self::assertSame('', $result['mobile']['webp']);
        self::assertSame('', $result['desktop']['webp']);
        self::assertNotEmpty($result['mobile']['avif']);
        self::assertNotEmpty($result['mobile']['jpeg']);
        self::assertNotEmpty($result['desktop']['avif']);
        self::assertNotEmpty($result['desktop']['jpeg']);
    }

    public function testAvifFailureProducesEmptyAvifButKeepsWebpAndJpeg(): void
    {
        $breakpoints = ['tablet' => 800];
        $imageService = $this->stubFormatFails('avif');
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $subject = new ImageVariantService($imageService, $this->eventDispatcher);
        $result = $subject->processVariants($this->fileReference, $breakpoints);

        self::assertSame(800, $result['tablet']['width']);
        self::assertSame('', $result['tablet']['avif']);
        self::assertNotEmpty($result['tablet']['webp']);
        self::assertNotEmpty($result['tablet']['jpeg']);
    }

    public function testJpegFailureProducesEmptyJpegButKeepsAvifAndWebp(): void
    {
        $breakpoints = ['desktop' => 1600];
        $imageService = $this->stubFormatFails('jpeg');
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $subject = new ImageVariantService($imageService, $this->eventDispatcher);
        $result = $subject->processVariants($this->fileReference, $breakpoints);

        self::assertSame(1600, $result['desktop']['width']);
        self::assertSame('', $result['desktop']['jpeg']);
        self::assertNotEmpty($result['desktop']['avif']);
        self::assertNotEmpty($result['desktop']['webp']);
    }

    public function testMultipleFormatFailuresAllFailedAreEmpty(): void
    {
        $breakpoints = ['mobile' => 400];
        $imageService = $this->stubFormatsFail(['avif', 'webp']);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $subject = new ImageVariantService($imageService, $this->eventDispatcher);
        $result = $subject->processVariants($this->fileReference, $breakpoints);

        self::assertSame('', $result['mobile']['avif']);
        self::assertSame('', $result['mobile']['webp']);
        self::assertNotEmpty($result['mobile']['jpeg']);
    }

    public function testAllFormatsFailForSingleBreakpointProducesAllEmpty(): void
    {
        $breakpoints = ['mobile' => 400, 'desktop' => 1200];
        $imageService = $this->stubWidthFails(400);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $subject = new ImageVariantService($imageService, $this->eventDispatcher);
        $result = $subject->processVariants($this->fileReference, $breakpoints);

        self::assertSame('', $result['mobile']['avif']);
        self::assertSame('', $result['mobile']['webp']);
        self::assertSame('', $result['mobile']['jpeg']);
        self::assertNotEmpty($result['desktop']['avif']);
        self::assertNotEmpty($result['desktop']['webp']);
        self::assertNotEmpty($result['desktop']['jpeg']);
    }

    public function testDispatchesBeforeAndAfterEvents(): void
    {
        $breakpoints = ['mobile' => 400];
        $imageService = $this->stubAllSucceed();
        $dispatchedEvents = [];
        $this->eventDispatcher
            ->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;
                return $event;
            });
        $subject = new ImageVariantService($imageService, $this->eventDispatcher);
        $subject->processVariants($this->fileReference, $breakpoints);

        self::assertCount(2, $dispatchedEvents);
        self::assertInstanceOf(BeforeImageProcessingEvent::class, $dispatchedEvents[0]);
        self::assertInstanceOf(AfterImageProcessedEvent::class, $dispatchedEvents[1]);
        self::assertSame($this->fileReference, $dispatchedEvents[0]->getFileReference());
        self::assertSame($this->fileReference, $dispatchedEvents[1]->getFileReference());
    }

    public function testCancelledBeforeEventSkipsProcessingReturnsEmptyArray(): void
    {
        $breakpoints = ['mobile' => 400];
        $imageService = $this->stubNeverCalled();
        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function (object $event): object {
                if ($event instanceof BeforeImageProcessingEvent) {
                    $event->cancel();
                }
                return $event;
            });
        $subject = new ImageVariantService($imageService, $this->eventDispatcher);
        $result = $subject->processVariants($this->fileReference, $breakpoints);

        self::assertSame([], $result);
    }

    public function testModifiedBreakpointsFromBeforeEventAreUsed(): void
    {
        $original = ['mobile' => 400];
        $modified = ['mobile' => 400, 'tablet' => 800];
        $calls = new \ArrayObject();
        $imageService = $this->stubRecordCalls($calls);
        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function (object $event) use ($modified): object {
                if ($event instanceof BeforeImageProcessingEvent) {
                    $event->setBreakpoints($modified);
                }
                return $event;
            });
        $subject = new ImageVariantService($imageService, $this->eventDispatcher);
        $result = $subject->processVariants($this->fileReference, $original);

        self::assertArrayHasKey('mobile', $result);
        self::assertArrayHasKey('tablet', $result);
        $widths = array_unique(array_column($calls->getArrayCopy(), 'width'));
        self::assertContains(400, $widths);
        self::assertContains(800, $widths);
    }

    public function testGeneratedUrisAreWellFormed(): void
    {
        $breakpoints = ['mobile' => 400];
        $imageService = $this->stubAllSucceed();
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $subject = new ImageVariantService($imageService, $this->eventDispatcher);
        $result = $subject->processVariants($this->fileReference, $breakpoints);

        foreach (['avif', 'webp', 'jpeg'] as $format) {
            self::assertStringStartsWith('/f/', $result['mobile'][$format]);
        }
    }

    public function testMultipleBreakpointsReturnCorrectStructure(): void
    {
        $breakpoints = ['xs' => 320, 'sm' => 640, 'md' => 1024, 'lg' => 1440];
        $imageService = $this->stubAllSucceed();
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $subject = new ImageVariantService($imageService, $this->eventDispatcher);
        $result = $subject->processVariants($this->fileReference, $breakpoints);

        self::assertCount(4, $result);
        self::assertSame(320, $result['xs']['width']);
        self::assertSame(640, $result['sm']['width']);
        self::assertSame(1024, $result['md']['width']);
        self::assertSame(1440, $result['lg']['width']);
        foreach (['xs', 'sm', 'md', 'lg'] as $bucket) {
            self::assertNotEmpty($result[$bucket]['avif']);
            self::assertNotEmpty($result[$bucket]['webp']);
            self::assertNotEmpty($result[$bucket]['jpeg']);
        }
    }

    public function testAfterEventCanModifyVariants(): void
    {
        $breakpoints = ['mobile' => 400];
        $imageService = $this->stubAllSucceed();
        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function (object $event): object {
                if ($event instanceof AfterImageProcessedEvent) {
                    $variants = $event->getVariants();
                    $variants['mobile']['avif'] = '/custom/overridden.avif';
                    $event->setVariants($variants);
                }
                return $event;
            });
        $subject = new ImageVariantService($imageService, $this->eventDispatcher);
        $result = $subject->processVariants($this->fileReference, $breakpoints);

        self::assertSame('/custom/overridden.avif', $result['mobile']['avif']);
    }

    private function stubAllSucceed(): ImageService
    {
        return new readonly class extends ImageService {
            public function __construct()
            {
            }
            public function applyProcessingInstructions($image, array $instructions): ProcessedFile
            {
                return new class extends ProcessedFile {
                    public function __construct()
                    {
                    }
                };
            }
            public function getImageUri($file, bool $absolute = false): string
            {
                return '/f/image.jpg';
            }
        };
    }

    private function stubFormatFails(string $format): ImageService
    {
        return new readonly class ($format) extends ImageService {
            public function __construct(private readonly string $failFormat)
            {
            }
            public function applyProcessingInstructions($image, array $instructions): ProcessedFile
            {
                $fmt = $instructions['format'];
                $isFail = $this->failFormat === 'jpeg'
                    ? $fmt === 'jpg'
                    : $fmt === $this->failFormat;
                if ($isFail) {
                    throw new RuntimeException('Unsupported format');
                }
                return new class extends ProcessedFile {
                    public function __construct()
                    {
                    }
                };
            }
            public function getImageUri($file, bool $absolute = false): string
            {
                return '/f/image.jpg';
            }
        };
    }

    private function stubFormatsFail(array $formats): ImageService
    {
        return new readonly class ($formats) extends ImageService {
            public function __construct(private readonly array $failFormats)
            {
            }
            public function applyProcessingInstructions($image, array $instructions): ProcessedFile
            {
                $fmt = $instructions['format'];
                foreach ($this->failFormats as $fail) {
                    if ($fail === 'jpeg' ? $fmt === 'jpg' : $fmt === $fail) {
                        throw new RuntimeException('Unsupported format');
                    }
                }
                return new class extends ProcessedFile {
                    public function __construct()
                    {
                    }
                };
            }
            public function getImageUri($file, bool $absolute = false): string
            {
                return '/f/image.jpg';
            }
        };
    }

    private function stubWidthFails(int $width): ImageService
    {
        return new readonly class ($width) extends ImageService {
            public function __construct(private readonly int $failWidth)
            {
            }
            public function applyProcessingInstructions($image, array $instructions): ProcessedFile
            {
                if ($instructions['width'] === $this->failWidth) {
                    throw new RuntimeException('Processing failed');
                }
                return new class extends ProcessedFile {
                    public function __construct()
                    {
                    }
                };
            }
            public function getImageUri($file, bool $absolute = false): string
            {
                return '/f/image.jpg';
            }
        };
    }

    private function stubNeverCalled(): ImageService
    {
        return new readonly class extends ImageService {
            public function __construct()
            {
            }
            public function applyProcessingInstructions($image, array $instructions): ProcessedFile
            {
                throw new \LogicException('must not be called');
            }
            public function getImageUri($file, bool $absolute = false): string
            {
                throw new \LogicException('must not be called');
            }
        };
    }

    private function stubRecordCalls(\ArrayObject $log): ImageService
    {
        return new readonly class ($log) extends ImageService {
            public function __construct(private readonly \ArrayObject $log)
            {
            }
            public function applyProcessingInstructions($image, array $instructions): ProcessedFile
            {
                $this->log->append($instructions);
                return new class extends ProcessedFile {
                    public function __construct()
                    {
                    }
                };
            }
            public function getImageUri($file, bool $absolute = false): string
            {
                return '/f/image.jpg';
            }
        };
    }
}
