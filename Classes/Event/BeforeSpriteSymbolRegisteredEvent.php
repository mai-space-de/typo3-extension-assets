<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Event;

/**
 * Dispatched for every SVG symbol discovered in a `Configuration/SpriteIcons.php` file
 * across all loaded TYPO3 extensions, before the symbol is stored in the registry.
 *
 * Listeners can:
 *  - Modify the symbol ID (rename the icon) via setSymbolId()
 *  - Override the source path or other config values via setConfig()
 *  - Completely veto the symbol (exclude it from the sprite) via skip()
 *
 * Example use cases:
 *  - A project package renames icons from vendor naming to its own convention
 *  - A multi-site setup filters out icons that should not appear on a specific site
 *  - Debugging: log which extensions contribute which icons
 *
 * Registration example in your site package's Services.yaml:
 *
 *   MyVendor\MySitePackage\EventListener\MyIconFilterListener:
 *       tags:
 *           -   name: event.listener
 *               identifier: 'my-site-icon-filter'
 *               event: Maispace\MaispaceAssets\Event\BeforeSpriteSymbolRegisteredEvent
 *
 * @see \Maispace\MaispaceAssets\Registry\SpriteIconRegistry
 */
final class BeforeSpriteSymbolRegisteredEvent
{
    private bool $skipped = false;

    /**
     * @param string $symbolId           The array key from SpriteIcons.php (used as symbol ID)
     * @param array  $config             The registration config, e.g. ['src' => 'EXT:...']
     * @param string $sourceExtensionKey The extension key whose SpriteIcons.php declared this symbol
     */
    public function __construct(
        private string $symbolId,
        /** @var array<string, mixed> */
        private array $config,
        private readonly string $sourceExtensionKey,
    ) {
    }

    /**
     * The symbol ID as declared in SpriteIcons.php (the array key).
     * This becomes the `id` attribute of the `<symbol>` element and the fragment
     * used in `<use href="/sprite.svg#symbol-id">` references.
     */
    public function getSymbolId(): string
    {
        return $this->symbolId;
    }

    /**
     * Rename the symbol. The new ID will be used as the `<symbol id="">` attribute.
     * Update any corresponding <mai:svgSprite use="..."> calls if you rename icons.
     */
    public function setSymbolId(string $symbolId): void
    {
        $this->symbolId = $symbolId;
    }

    /**
     * The raw configuration array for this symbol.
     * Currently supports:
     *   'src' (string, required) — EXT: path or absolute path to the .svg file.
     */
    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Replace the configuration array, e.g. to point to a different SVG file.
     */
    /**
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * The extension key (e.g. "my_sitepackage") whose SpriteIcons.php contributed this symbol.
     * Useful for filtering icons by origin.
     */
    public function getSourceExtensionKey(): string
    {
        return $this->sourceExtensionKey;
    }

    /**
     * Veto this symbol — it will NOT be included in the sprite.
     * Idempotent: calling skip() multiple times has no additional effect.
     */
    public function skip(): void
    {
        $this->skipped = true;
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }
}
