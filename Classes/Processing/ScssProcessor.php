<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Processing;

use Maispace\MaiAssets\Event\AfterScssCompiledEvent;
use Maispace\MaiAssets\Exception\AssetCompilationException;
use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\OutputStyle;

final class ScssProcessor extends AbstractAssetProcessor
{
    public function canProcess(string $filePath): bool
    {
        return strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'scss';
    }

    protected function doProcess(string $content, string $sourcePath): string
    {
        $compiler = new Compiler();
        $compiler->setOutputStyle(OutputStyle::COMPRESSED);

        $sourceDir = dirname($sourcePath);
        $compiler->setImportPaths([$sourceDir]);

        try {
            $result = $compiler->compileString($content, $sourcePath);
            $css = $result->getCss();

            $event = new AfterScssCompiledEvent($css, $sourcePath);
            $this->eventDispatcher->dispatch($event);

            return $event->getCompiledCss();
        } catch (\Exception $e) {
            throw new AssetCompilationException(
                sprintf('SCSS compilation error in file "%s": %s', $sourcePath, $e->getMessage()),
                1700000002,
                $e
            );
        }
    }

    protected function getSettingsHash(): array
    {
        return ['type' => 'scss'];
    }

    protected function getCacheExtension(): string
    {
        return 'css';
    }
}
