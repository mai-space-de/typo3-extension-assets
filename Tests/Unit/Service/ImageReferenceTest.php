<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Service;

use Maispace\MaiAssets\Service\ImageReference;
use Maispace\MaiAssets\Service\ImageVariantService;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Imaging\ImageManipulation\Area;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Extbase\Service\ImageService;

final class ImageReferenceTest extends TestCase
{
    public function testExtbaseModelResolvesToTheCoreReference(): void
    {
        $reference = $this->referenceWithCrop();
        $model = new class ($reference) {
            public function __construct(private object $resource) {}

            public function getOriginalResource(): object
            {
                return $this->resource;
            }
        };

        self::assertSame($reference, ImageReference::normalize($model));
    }

    public function testCropFromTheReferenceIsAddedAsAbsoluteArea(): void
    {
        $instructions = ImageReference::withReferenceCrop($this->referenceWithCrop(), ['width' => 400]);

        self::assertArrayHasKey('crop', $instructions);
        $crop = $instructions['crop'];
        self::assertInstanceOf(Area::class, $crop);
        self::assertEqualsWithDelta(100.0, $crop->getOffsetLeft(), 0.01);
        self::assertEqualsWithDelta(160.0, $crop->getOffsetTop(), 0.01);
        self::assertEqualsWithDelta(500.0, $crop->getWidth(), 0.01);
        self::assertEqualsWithDelta(320.0, $crop->getHeight(), 0.01);
    }

    public function testMissingCropLeavesInstructionsUntouched(): void
    {
        $reference = new class extends FileReference {
            public function __construct() {}

            public function hasProperty(string $key): bool
            {
                return false;
            }
        };

        self::assertSame(['width' => 400], ImageReference::withReferenceCrop($reference, ['width' => 400]));
    }

    public function testExplicitCropIsNotReplaced(): void
    {
        $instructions = ['width' => 400, 'crop' => 'keep'];

        self::assertSame($instructions, ImageReference::withReferenceCrop($this->referenceWithCrop(), $instructions));
    }

    public function testVariantProcessingForwardsTheReferenceCrop(): void
    {
        $reference = $this->referenceWithCrop();
        $seen = new CropInstructionCapture();
        $imageService = new readonly class ($seen) extends ImageService {
            public function __construct(private CropInstructionCapture $seen) {}

            public function applyProcessingInstructions($image, array $instructions): ProcessedFile
            {
                $this->seen->instructions = $instructions;

                return new class extends ProcessedFile {
                    public function __construct() {}
                };
            }

            public function getImageUri($file, bool $absolute = false): string
            {
                return '/f/image.jpg';
            }
        };
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        (new ImageVariantService($imageService, $dispatcher))->processVariants($reference, ['mobile' => 400]);

        self::assertInstanceOf(Area::class, $seen->instructions['crop'] ?? null);
    }

    private function referenceWithCrop(): FileReference
    {
        return new class extends FileReference {
            public function __construct() {}

            public function hasProperty(string $key): bool
            {
                return in_array($key, ['crop', 'width', 'height'], true);
            }

            public function getProperty(string $key): mixed
            {
                return match ($key) {
                    'crop' => '{"default":{"cropArea":{"x":0.1,"y":0.2,"width":0.5,"height":0.4}}}',
                    'width' => 1000,
                    'height' => 800,
                    default => null,
                };
            }
        };
    }
}

/**
 * @internal
 */
final class CropInstructionCapture
{
    /** @var array<string, mixed> */
    public array $instructions = [];
}
