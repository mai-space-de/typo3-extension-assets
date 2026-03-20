<?php

declare(strict_types = 1);

namespace Maispace\MaiAssets\Tests\Unit\Service;

use Maispace\MaiAssets\Service\ImageRenderingService;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Extbase\Service\ImageService;

/**
 * Verifies that ImageRenderingService::resolveImage() returns null and logs
 * a warning for invalid or missing inputs instead of throwing exceptions.
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
    // Unsupported types → returns null (logs warning)
    // -------------------------------------------------------------------------

    public function testResolveImageReturnsNullForNull(): void
    {
        self::assertNull($this->subject->resolveImage(null));
    }

    public function testResolveImageReturnsNullForEmptyString(): void
    {
        self::assertNull($this->subject->resolveImage(''));
    }

    public function testResolveImageReturnsNullForBooleanInput(): void
    {
        self::assertNull($this->subject->resolveImage(true));
    }

    public function testResolveImageReturnsNullForArrayInput(): void
    {
        self::assertNull($this->subject->resolveImage([]));
    }

    public function testResolveImageReturnsNullForArbitraryObject(): void
    {
        self::assertNull($this->subject->resolveImage(new \stdClass()));
    }

    public function testResolveImageReturnsNullForDouble(): void
    {
        self::assertNull($this->subject->resolveImage(3.14));
    }

    // -------------------------------------------------------------------------
    // FAL UID lookup failure → returns null (logs warning)
    // -------------------------------------------------------------------------

    public function testResolveImageReturnsNullWhenFileReferenceUidDoesNotExist(): void
    {
        $this->resourceFactory
            ->method('getFileReferenceObject')
            ->willThrowException(new \RuntimeException('Record not found'));

        self::assertNull($this->subject->resolveImage(99999));
    }

    public function testResolveImageReturnsNullOnFileReferenceLookupFailure(): void
    {
        $this->resourceFactory
            ->method('getFileReferenceObject')
            ->willThrowException(new \RuntimeException('DB error'));

        self::assertNull($this->subject->resolveImage(1));
    }

    public function testResolveImageReturnsNullWhenNumericStringUidDoesNotExist(): void
    {
        $this->resourceFactory
            ->method('getFileReferenceObject')
            ->willThrowException(new \RuntimeException('Not found'));

        self::assertNull($this->subject->resolveImage('42'));
    }

    // -------------------------------------------------------------------------
    // Valid inputs — returns the object unchanged
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
