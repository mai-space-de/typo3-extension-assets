<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Tests\Unit\Service;

use Maispace\MaispaceAssets\Exception\AssetFileNotFoundException;
use Maispace\MaispaceAssets\Exception\InvalidImageInputException;
use Maispace\MaispaceAssets\Service\ImageRenderingService;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Extbase\Service\ImageService;

/**
 * Verifies that ImageRenderingService::resolveImage() throws typed exceptions
 * instead of silently returning null for invalid or missing inputs.
 */
final class ImageRenderingServiceExceptionTest extends TestCase
{
    private ImageService&\PHPUnit\Framework\MockObject\MockObject $imageService;
    private ResourceFactory&\PHPUnit\Framework\MockObject\MockObject $resourceFactory;
    private ImageRenderingService $subject;

    protected function setUp(): void
    {
        $this->imageService = $this->createMock(ImageService::class);
        $this->resourceFactory = $this->createMock(ResourceFactory::class);

        $this->subject = new ImageRenderingService(
            $this->imageService,
            $this->createMock(LoggerInterface::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->resourceFactory,
            $this->createMock(PageRenderer::class),
        );
    }

    // -------------------------------------------------------------------------
    // Unsupported types → InvalidImageInputException
    // -------------------------------------------------------------------------

    public function testResolveImageThrowsForNull(): void
    {
        $this->expectException(InvalidImageInputException::class);
        $this->expectExceptionMessageMatches('/Unsupported image input type/');

        $this->subject->resolveImage(null);
    }

    public function testResolveImageThrowsForEmptyString(): void
    {
        $this->expectException(InvalidImageInputException::class);
        $this->expectExceptionMessageMatches('/Unsupported image input type/');

        $this->subject->resolveImage('');
    }

    public function testResolveImageThrowsForBooleanInput(): void
    {
        $this->expectException(InvalidImageInputException::class);
        $this->expectExceptionMessageMatches('/Unsupported image input type/');

        $this->subject->resolveImage(true);
    }

    public function testResolveImageThrowsForArrayInput(): void
    {
        $this->expectException(InvalidImageInputException::class);
        $this->expectExceptionMessageMatches('/Unsupported image input type/');

        $this->subject->resolveImage([]);
    }

    public function testResolveImageThrowsForArbitraryObject(): void
    {
        $this->expectException(InvalidImageInputException::class);
        $this->expectExceptionMessageMatches('/Unsupported image input type/');

        $this->subject->resolveImage(new \stdClass());
    }

    public function testExceptionMessageContainsActualType(): void
    {
        try {
            $this->subject->resolveImage(3.14);
            self::fail('Expected InvalidImageInputException was not thrown.');
        } catch (InvalidImageInputException $e) {
            self::assertStringContainsString('double', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // FAL UID lookup failure → AssetFileNotFoundException
    // -------------------------------------------------------------------------

    public function testResolveImageThrowsWhenFileReferenceUidDoesNotExist(): void
    {
        $this->resourceFactory
            ->method('getFileReferenceObject')
            ->willThrowException(new \RuntimeException('Record not found'));

        $this->expectException(AssetFileNotFoundException::class);
        $this->expectExceptionMessageMatches('/Could not resolve FileReference/');

        $this->subject->resolveImage(99999);
    }

    public function testResolveImageWrapsOriginalExceptionAsChained(): void
    {
        $original = new \RuntimeException('DB error');
        $this->resourceFactory
            ->method('getFileReferenceObject')
            ->willThrowException($original);

        try {
            $this->subject->resolveImage(1);
            self::fail('Expected AssetFileNotFoundException was not thrown.');
        } catch (AssetFileNotFoundException $e) {
            self::assertSame($original, $e->getPrevious());
        }
    }

    public function testResolveImageThrowsWhenNumericStringUidDoesNotExist(): void
    {
        $this->resourceFactory
            ->method('getFileReferenceObject')
            ->willThrowException(new \RuntimeException('Not found'));

        $this->expectException(AssetFileNotFoundException::class);

        $this->subject->resolveImage('42');
    }

    // -------------------------------------------------------------------------
    // Return type — resolveImage no longer returns null
    // -------------------------------------------------------------------------

    public function testResolveImageWithValidFileObjectReturnsIt(): void
    {
        $file = $this->createMock(\TYPO3\CMS\Core\Resource\File::class);

        $result = $this->subject->resolveImage($file);

        self::assertSame($file, $result);
    }

    public function testResolveImageWithFileReferenceObjectReturnsIt(): void
    {
        $ref = $this->createMock(\TYPO3\CMS\Core\Resource\FileReference::class);

        $result = $this->subject->resolveImage($ref);

        self::assertSame($ref, $result);
    }
}
