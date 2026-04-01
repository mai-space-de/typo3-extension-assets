<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Event;

final class AfterSpriteBuiltEvent
{
    public function __construct(private string $sprite) {}

    public function getSprite(): string
    {
        return $this->sprite;
    }

    public function setSprite(string $sprite): void
    {
        $this->sprite = $sprite;
    }
}
