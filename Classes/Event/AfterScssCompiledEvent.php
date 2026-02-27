<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Event;

/**
 * Dispatched after SCSS has been compiled to CSS by ScssCompilerService, but before
 * the result is cached or registered with TYPO3's AssetCollector.
 *
 * Event listeners can:
 *  - Inspect the original SCSS source via getOriginalScss()
 *  - Post-process the compiled CSS via setCompiledCss()
 *  - Add vendor prefixes, CSS custom properties, or additional rules
 *
 * Registration example in your site package's Services.yaml:
 *
 *   MyVendor\MySitePackage\EventListener\MyScssListener:
 *       tags:
 *           -   name: event.listener
 *               identifier: 'my-site-scss-processor'
 *               event: Maispace\MaispaceAssets\Event\AfterScssCompiledEvent
 *
 * @see \Maispace\MaispaceAssets\Service\AssetProcessingService::handleScss()
 * @see \Maispace\MaispaceAssets\Service\ScssCompilerService
 */
final class AfterScssCompiledEvent
{
    public function __construct(
        private string $identifier,
        private readonly string $originalScss,
        private string $compiledCss,
        private readonly array $viewHelperArguments,
    ) {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * The raw SCSS source that was passed to the compiler.
     * This is read-only — use setCompiledCss() to influence the output.
     */
    public function getOriginalScss(): string
    {
        return $this->originalScss;
    }

    public function getCompiledCss(): string
    {
        return $this->compiledCss;
    }

    /**
     * Replace the compiled CSS that will be cached and registered with the AssetCollector.
     */
    public function setCompiledCss(string $css): void
    {
        $this->compiledCss = $css;
    }

    /**
     * The original ViewHelper argument array, e.g. ['identifier', 'src', 'priority',
     * 'minify', 'inline', 'importPaths'].
     */
    public function getViewHelperArguments(): array
    {
        return $this->viewHelperArguments;
    }

    /**
     * Convenience: whether the compiled CSS is rendered as an inline <style> tag.
     */
    public function isInline(): bool
    {
        return (bool)($this->viewHelperArguments['inline'] ?? false);
    }
}
