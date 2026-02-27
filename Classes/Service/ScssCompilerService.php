<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Service;

use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\OutputStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Wraps scssphp to compile SCSS content to CSS.
 *
 * This service is intentionally thin — it handles path resolution and compiler
 * configuration, but leaves caching and asset registration to AssetProcessingService.
 *
 * Example usage from AssetProcessingService:
 *
 *   $compiler = GeneralUtility::makeInstance(ScssCompilerService::class);
 *   $css = $compiler->compileFile('EXT:my_ext/Resources/Private/Scss/main.scss', [], true);
 *
 * SCSS @import / @use resolution:
 *   - If compiling a file, its directory is automatically added as an import path.
 *   - Additional import paths can be passed via $importPaths (EXT: notation supported).
 *
 * Compression vs. minification:
 *   When $compressed = true, scssphp uses OutputStyle::COMPRESSED which removes all
 *   unnecessary whitespace. This is functionally equivalent to a CSS minifier pass and
 *   avoids a redundant double-pass through matthiasmullie/minify for SCSS output.
 */
final class ScssCompilerService
{
    /**
     * Compile a raw SCSS string to CSS.
     *
     * @param string      $scssContent    Raw SCSS source
     * @param string[]    $importPaths    Additional import paths (EXT: notation or absolute)
     * @param bool        $compressed     Use OutputStyle::COMPRESSED (minified output)
     * @param string|null $sourceFilePath Absolute path of the source file (for relative @import resolution)
     *
     * @throws \ScssPhp\ScssPhp\Exception\SassException on invalid SCSS syntax
     */
    public function compile(
        string $scssContent,
        array $importPaths = [],
        bool $compressed = false,
        ?string $sourceFilePath = null,
    ): string {
        $compiler = new Compiler();

        $compiler->setOutputStyle(
            $compressed ? OutputStyle::COMPRESSED : OutputStyle::EXPANDED,
        );

        $resolvedPaths = $this->resolveImportPaths($importPaths);

        // Add the source file's directory as the primary import path so that
        // relative @import statements like @import 'variables' resolve correctly.
        if ($sourceFilePath !== null) {
            array_unshift($resolvedPaths, dirname($sourceFilePath));
        }

        if ($resolvedPaths !== []) {
            $compiler->setImportPaths($resolvedPaths);
        }

        return $compiler->compileString($scssContent)->getCss();
    }

    /**
     * Compile an SCSS file to CSS.
     *
     * The file's directory is added automatically as an import path.
     * Additional paths can be provided via $importPaths.
     *
     * @param string   $absoluteFilePath Absolute path to the .scss file
     * @param string[] $importPaths      Additional EXT: or absolute import paths
     * @param bool     $compressed       Use OutputStyle::COMPRESSED
     *
     * @throws \RuntimeException                        if the file cannot be read
     * @throws \ScssPhp\ScssPhp\Exception\SassException on invalid SCSS syntax
     */
    public function compileFile(
        string $absoluteFilePath,
        array $importPaths = [],
        bool $compressed = false,
    ): string {
        $scssContent = @file_get_contents($absoluteFilePath);
        if ($scssContent === false) {
            throw new \RuntimeException('SCSS file is not readable: ' . $absoluteFilePath, 1_700_000_001);
        }

        return $this->compile($scssContent, $importPaths, $compressed, $absoluteFilePath);
    }

    /**
     * Resolve a list of EXT: or absolute import paths to absolute filesystem paths.
     * Non-existent or non-directory paths are silently skipped.
     *
     * @param string[] $importPaths
     *
     * @return string[]
     */
    private function resolveImportPaths(array $importPaths): array
    {
        $resolved = [];
        foreach ($importPaths as $path) {
            $absolute = GeneralUtility::getFileAbsFileName(trim($path));
            if ($absolute !== '' && is_dir($absolute)) {
                $resolved[] = $absolute;
            }
        }

        return $resolved;
    }
}
