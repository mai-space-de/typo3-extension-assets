<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Event;

final class BeforeAssetInjectionEvent
{
    public function __construct(
        private string $content,
        private readonly string $type,
        private readonly string $source,
    ) {}

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSource(): string
    {
        return $this->source;
    }
}
