<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Event;

final class AfterImageProcessedEvent
{
    public function __construct(
        private array $variants,
        private readonly object $fileReference,
    ) {}

    public function getVariants(): array
    {
        return $this->variants;
    }

    public function setVariants(array $variants): void
    {
        $this->variants = $variants;
    }

    public function getFileReference(): object
    {
        return $this->fileReference;
    }
}
