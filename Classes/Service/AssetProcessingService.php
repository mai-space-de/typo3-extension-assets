<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\Service;

use Maispace\MaispaceAssets\Cache\AssetCacheManager;
use Maispace\MaispaceAssets\Event\AfterCssProcessedEvent;
use Maispace\MaispaceAssets\Event\AfterJsProcessedEvent;
use Maispace\MaispaceAssets\Event\AfterScssCompiledEvent;
use MatthiasMullie\Minify;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Central service for processing CSS, JS, and SCSS assets.
 *
 * All ViewHelpers delegate to the static entry points here (handleCss, handleJs, handleScss).
 * Static methods are required because the ViewHelpers use CompileWithRenderStatic.
 * Dependencies are resolved via GeneralUtility::makeInstance() which respects the DI container.
 *
 * Processing pipeline (per asset type):
 *  1. Resolve source (file path or inline content from ViewHelper children)
 *  2. Build a stable cache/file identifier
 *  3. Check cache — skip processing if hit
 *  4. Minify / compile SCSS
 *  5. Dispatch PSR-14 event (listeners can modify the output)
 *  6. Store in cache
 *  7. Write to typo3temp/ and register with AssetCollector
 *
 * TypoScript path: plugin.tx_maispace_assets.{css|js|scss}.{setting}
 */
final class AssetProcessingService
{
    // -------------------------------------------------------------------------
    // Public static entry points (called from ViewHelpers)
    // -------------------------------------------------------------------------

    /**
     * Process a CSS asset and register it with TYPO3's AssetCollector.
     *
     * @param array       $arguments    ViewHelper arguments (identifier, src, priority, minify, inline, deferred, media)
     * @param string|null $inlineContent Content captured from the ViewHelper's child nodes
     */
    public static function handleCss(array $arguments, ?string $inlineContent): void
    {
        $cache       = self::cache();
        $dispatcher  = self::dispatcher();
        $logger      = self::logger();
        $collector   = self::collector();

        // 1. Resolve source content and determine whether it is file-based.
        [$content, $absoluteSrc, $isFileBased] = self::resolveSource(
            $arguments['src'] ?? null,
            $inlineContent,
        );

        if ($content === null) {
            return; // No content, nothing to do.
        }

        // 2. Build a stable identifier.
        $identifier = self::buildIdentifier(
            $arguments['identifier'] ?? null,
            $arguments['src'] ?? null,
            $content,
            'css',
        );

        // 3. Determine effective settings (argument overrides TypoScript).
        $shouldMinify = self::resolveFlag('minify', $arguments['minify'] ?? null, 'css');
        $isDeferred   = self::resolveFlag('deferred', $arguments['deferred'] ?? null, 'css');
        $isInline     = (bool)($arguments['inline'] ?? false);
        $isPriority   = (bool)($arguments['priority'] ?? false);
        $media        = $arguments['media'] ?? 'all';

        // 4. Check cache.
        $cacheKey = $cache->buildCssKey($identifier, $shouldMinify);
        if ($cache->has($cacheKey)) {
            $processed = $cache->get($cacheKey);
        } else {
            // 5. Minify if requested.
            $processed = $shouldMinify ? self::minifyCss($content, $absoluteSrc) : $content;

            // 6. Dispatch event (listeners may modify $processed).
            /** @var AfterCssProcessedEvent $event */
            $event = $dispatcher->dispatch(
                new AfterCssProcessedEvent($identifier, $processed, $arguments),
            );
            $processed = $event->getProcessedCss();

            // 7. Store in cache.
            $cache->set($cacheKey, $processed, ['maispace_assets_css']);
        }

        // 8. Register with AssetCollector.
        if ($isInline) {
            $collector->addInlineStyleSheet(
                $identifier,
                $processed,
                [],
                ['priority' => $isPriority],
            );
            return;
        }

        // Write to typo3temp/ and get the public-relative path.
        $publicPath = self::writeToTemp($processed, $identifier, 'css');
        if ($publicPath === null) {
            $logger->error('maispace_assets: Could not write CSS file for identifier ' . $identifier);
            return;
        }

        if ($isDeferred) {
            // Deferred non-blocking load via media="print" onload swap trick.
            // The browser loads the stylesheet without blocking render, then the onload
            // handler switches media to "all", applying the styles.
            // A <noscript> fallback is appended via the deferred attribute handling below.
            $collector->addStyleSheet(
                $identifier,
                $publicPath,
                [
                    'media'  => 'print',
                    'onload' => "this.media='" . addslashes($media) . "'",
                ],
                ['priority' => false], // deferred CSS is never in <head>
            );
            // Noscript fallback — registered as a separate inline block.
            $collector->addInlineStyleSheet(
                $identifier . '_noscript',
                '<noscript><link rel="stylesheet" href="' . htmlspecialchars($publicPath) . '"></noscript>',
                [],
                ['priority' => false],
            );
        } else {
            $collector->addStyleSheet(
                $identifier,
                $publicPath,
                ['media' => $media],
                ['priority' => $isPriority],
            );
        }
    }

    /**
     * Process a JS asset and register it with TYPO3's AssetCollector.
     *
     * @param array       $arguments    ViewHelper arguments (identifier, src, priority, minify, defer, async, type)
     * @param string|null $inlineContent Content captured from the ViewHelper's child nodes
     */
    public static function handleJs(array $arguments, ?string $inlineContent): void
    {
        $cache      = self::cache();
        $dispatcher = self::dispatcher();
        $logger     = self::logger();
        $collector  = self::collector();

        [$content, $absoluteSrc, $isFileBased] = self::resolveSource(
            $arguments['src'] ?? null,
            $inlineContent,
        );

        if ($content === null) {
            return;
        }

        $identifier = self::buildIdentifier(
            $arguments['identifier'] ?? null,
            $arguments['src'] ?? null,
            $content,
            'js',
        );

        $shouldMinify = self::resolveFlag('minify', $arguments['minify'] ?? null, 'js');
        $useDefer     = self::resolveFlag('defer', $arguments['defer'] ?? null, 'js');
        $useAsync     = (bool)($arguments['async'] ?? false);
        $isPriority   = (bool)($arguments['priority'] ?? false);
        $type         = $arguments['type'] ?? null;

        $cacheKey = $cache->buildJsKey($identifier, $shouldMinify);
        if ($cache->has($cacheKey)) {
            $processed = $cache->get($cacheKey);
        } else {
            $processed = $shouldMinify ? self::minifyJs($content, $absoluteSrc) : $content;

            /** @var AfterJsProcessedEvent $event */
            $event = $dispatcher->dispatch(
                new AfterJsProcessedEvent($identifier, $processed, $arguments),
            );
            $processed = $event->getProcessedJs();

            $cache->set($cacheKey, $processed, ['maispace_assets_js']);
        }

        // Inline JS.
        if (empty($arguments['src'])) {
            $collector->addInlineJavaScript(
                $identifier,
                $processed,
                [],
                ['priority' => $isPriority],
            );
            return;
        }

        // File-based JS.
        $publicPath = self::writeToTemp($processed, $identifier, 'js');
        if ($publicPath === null) {
            $logger->error('maispace_assets: Could not write JS file for identifier ' . $identifier);
            return;
        }

        $attributes = [];
        if ($useDefer) {
            $attributes['defer'] = 'defer';
        }
        if ($useAsync) {
            $attributes['async'] = 'async';
        }
        if ($type !== null) {
            $attributes['type'] = $type;
        }

        $collector->addJavaScript(
            $identifier,
            $publicPath,
            $attributes,
            ['priority' => $isPriority],
        );
    }

    /**
     * Compile SCSS to CSS, then register the result as a CSS asset.
     *
     * @param array       $arguments    ViewHelper arguments (identifier, src, priority, minify, inline, importPaths)
     * @param string|null $inlineContent Raw SCSS captured from the ViewHelper's child nodes
     */
    public static function handleScss(array $arguments, ?string $inlineContent): void
    {
        $cache      = self::cache();
        $dispatcher = self::dispatcher();
        $logger     = self::logger();

        $src         = $arguments['src'] ?? null;
        $isFileBased = $src !== null;

        if ($isFileBased) {
            $absoluteSrc = GeneralUtility::getFileAbsFileName($src);
            if ($absoluteSrc === '' || !is_file($absoluteSrc)) {
                $logger->warning('maispace_assets: SCSS file not found: ' . $src);
                return;
            }
            $rawScss   = (string)file_get_contents($absoluteSrc);
            $fileMtime = (int)filemtime($absoluteSrc);
        } else {
            $rawScss     = trim((string)$inlineContent);
            $absoluteSrc = null;
            $fileMtime   = null;
        }

        if ($rawScss === '') {
            return;
        }

        $identifier = self::buildIdentifier(
            $arguments['identifier'] ?? null,
            $src,
            $rawScss,
            'scss',
        );

        $shouldMinify = self::resolveFlag('minify', $arguments['minify'] ?? null, 'scss');

        $cacheKey = $cache->buildScssKey($identifier, $fileMtime);
        if ($cache->has($cacheKey)) {
            $compiledCss = $cache->get($cacheKey);
        } else {
            // Parse additional import paths from the argument.
            $importPaths = [];
            $importPathsArg = trim((string)($arguments['importPaths'] ?? ''));
            if ($importPathsArg !== '') {
                $importPaths = array_map('trim', explode(',', $importPathsArg));
            }

            // Add TypoScript default import paths.
            $tsDefault = trim((string)self::getTypoScriptSetting('scss.defaultImportPaths', ''));
            if ($tsDefault !== '') {
                $importPaths = array_merge(
                    $importPaths,
                    array_map('trim', explode(',', $tsDefault)),
                );
            }

            try {
                /** @var ScssCompilerService $compiler */
                $compiler = GeneralUtility::makeInstance(ScssCompilerService::class);
                $compiledCss = $compiler->compile(
                    $rawScss,
                    $importPaths,
                    $shouldMinify,
                    $absoluteSrc,
                );
            } catch (\ScssPhp\ScssPhp\Exception\SassException $e) {
                $logger->error(
                    'maispace_assets: SCSS compilation failed for "' . $identifier . '": ' . $e->getMessage(),
                );
                return;
            }

            /** @var AfterScssCompiledEvent $event */
            $event = $dispatcher->dispatch(
                new AfterScssCompiledEvent($identifier, $rawScss, $compiledCss, $arguments),
            );
            $compiledCss = $event->getCompiledCss();

            $lifetime = (int)self::getTypoScriptSetting('scss.cacheLifetime', 0);
            $cache->set($cacheKey, $compiledCss, ['maispace_assets_scss'], $lifetime);
        }

        // Re-use CSS registration logic with the compiled output.
        // Pass src=null so the CSS handler treats it as inline/computed content.
        $cssArguments = $arguments;
        unset($cssArguments['importPaths']);
        $cssArguments['src'] = null;

        // Write the compiled CSS and register it — bypass the CSS cache since SCSS has its own.
        self::registerCompiledCss($compiledCss, $identifier, $cssArguments);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Register pre-compiled CSS (from SCSS) directly with the AssetCollector,
     * bypassing the CSS minification cache (SCSS is already handled above).
     */
    private static function registerCompiledCss(
        string $css,
        string $identifier,
        array $arguments,
    ): void {
        $isInline   = (bool)($arguments['inline'] ?? false);
        $isPriority = (bool)($arguments['priority'] ?? false);
        $isDeferred = self::resolveFlag('deferred', $arguments['deferred'] ?? null, 'css');
        $media      = $arguments['media'] ?? 'all';
        $collector  = self::collector();
        $logger     = self::logger();

        if ($isInline) {
            $collector->addInlineStyleSheet($identifier, $css, [], ['priority' => $isPriority]);
            return;
        }

        $publicPath = self::writeToTemp($css, $identifier, 'css');
        if ($publicPath === null) {
            $logger->error('maispace_assets: Could not write compiled SCSS for identifier ' . $identifier);
            return;
        }

        if ($isDeferred) {
            $collector->addStyleSheet(
                $identifier,
                $publicPath,
                ['media' => 'print', 'onload' => "this.media='" . addslashes($media) . "'"],
                ['priority' => false],
            );
            $collector->addInlineStyleSheet(
                $identifier . '_noscript',
                '<noscript><link rel="stylesheet" href="' . htmlspecialchars($publicPath) . '"></noscript>',
                [],
                ['priority' => false],
            );
        } else {
            $collector->addStyleSheet(
                $identifier,
                $publicPath,
                ['media' => $media],
                ['priority' => $isPriority],
            );
        }
    }

    /**
     * Resolve the asset source content and absolute file path.
     *
     * Returns [content, absolutePath|null, isFileBased].
     * Returns [null, null, false] if the source could not be resolved.
     *
     * @return array{0: string|null, 1: string|null, 2: bool}
     */
    private static function resolveSource(?string $src, ?string $inlineContent): array
    {
        if ($src !== null) {
            $absolute = GeneralUtility::getFileAbsFileName($src);
            if ($absolute === '' || !is_file($absolute)) {
                self::logger()->warning('maispace_assets: Asset file not found: ' . $src);
                return [null, null, false];
            }
            $content = (string)file_get_contents($absolute);
            return [$content, $absolute, true];
        }

        $content = trim((string)$inlineContent);
        if ($content === '') {
            return [null, null, false];
        }

        return [$content, null, false];
    }

    /**
     * Build a stable, collision-resistant identifier string.
     *
     * When the user provides an explicit identifier, it is used as-is (prefixed
     * with 'maispace_' if no TypoScript prefix is configured).
     * Otherwise the identifier is derived from a hash of the source path or content,
     * so the same input always maps to the same identifier — preventing orphaned files.
     */
    private static function buildIdentifier(
        ?string $explicit,
        ?string $src,
        string $content,
        string $type,
    ): string {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        $prefix = (string)self::getTypoScriptSetting($type . '.identifierPrefix', 'maispace_');
        $hash   = $src !== null ? md5($src) : md5($content);

        return $prefix . $type . '_' . $hash;
    }

    /**
     * Resolve a boolean flag by checking the ViewHelper argument first,
     * then falling back to the TypoScript setting.
     *
     * A null argument means "use TypoScript default".
     */
    private static function resolveFlag(string $setting, ?bool $argumentValue, string $section): bool
    {
        if ($argumentValue !== null) {
            return $argumentValue;
        }
        return (bool)self::getTypoScriptSetting($section . '.' . $setting, false);
    }

    /**
     * Minify CSS content using matthiasmullie/minify.
     *
     * When $absolutePath is provided, the minifier can fix relative URLs in the CSS.
     */
    private static function minifyCss(string $css, ?string $absolutePath = null): string
    {
        $minifier = new Minify\CSS();
        if ($absolutePath !== null) {
            $minifier->add($absolutePath);
        } else {
            $minifier->add($css);
        }
        return $minifier->minify();
    }

    /**
     * Minify JS content using matthiasmullie/minify.
     */
    private static function minifyJs(string $js, ?string $absolutePath = null): string
    {
        $minifier = new Minify\JS();
        if ($absolutePath !== null) {
            $minifier->add($absolutePath);
        } else {
            $minifier->add($js);
        }
        return $minifier->minify();
    }

    /**
     * Write processed asset content to typo3temp/assets/maispace_assets/{type}/.
     *
     * Returns the public-relative path (suitable for AssetCollector) on success,
     * or null on failure.
     */
    private static function writeToTemp(string $content, string $identifier, string $type): ?string
    {
        $outputDir = ltrim(
            (string)self::getTypoScriptSetting($type . '.outputDir', 'typo3temp/assets/maispace_assets/' . $type . '/'),
            '/',
        );

        $absoluteDir = Environment::getPublicPath() . '/' . $outputDir;
        if (!is_dir($absoluteDir)) {
            GeneralUtility::mkdir_deep($absoluteDir);
        }

        $fileName     = sha1($identifier) . '.' . $type;
        $absoluteFile = $absoluteDir . $fileName;

        if (GeneralUtility::writeFile($absoluteFile, $content, true) === false) {
            return null;
        }

        GeneralUtility::fixPermissions($absoluteFile);

        // Return a root-relative path.
        return PathUtility::getAbsoluteWebPath($absoluteFile);
    }

    /**
     * Read a TypoScript setting from plugin.tx_maispace_assets.{dotPath}.
     *
     * Returns $default if the setting is not configured.
     *
     * Example: getTypoScriptSetting('css.minify', false)
     *   reads plugin.tx_maispace_assets.css.minify
     */
    private static function getTypoScriptSetting(string $dotPath, mixed $default): mixed
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request === null) {
            return $default;
        }

        /** @var \TYPO3\CMS\Core\TypoScript\FrontendTypoScript|null $frontendTypoScript */
        $frontendTypoScript = $request->getAttribute('frontend.typoscript');
        if ($frontendTypoScript === null) {
            return $default;
        }

        $setup = $frontendTypoScript->getSetupArray();
        $root  = $setup['plugin.']['tx_maispace_assets.'] ?? [];

        $parts = explode('.', $dotPath);
        $node  = $root;
        foreach ($parts as $i => $part) {
            $isLast = ($i === count($parts) - 1);
            if ($isLast) {
                return $node[$part] ?? $default;
            }
            $node = $node[$part . '.'] ?? [];
            if (!is_array($node)) {
                return $default;
            }
        }

        return $default;
    }

    // -------------------------------------------------------------------------
    // Dependency resolution via GeneralUtility::makeInstance
    // (required because ViewHelpers use static renderStatic)
    // -------------------------------------------------------------------------

    private static function cache(): AssetCacheManager
    {
        return GeneralUtility::makeInstance(AssetCacheManager::class);
    }

    private static function dispatcher(): EventDispatcherInterface
    {
        return GeneralUtility::makeInstance(EventDispatcherInterface::class);
    }

    private static function collector(): AssetCollector
    {
        return GeneralUtility::makeInstance(AssetCollector::class);
    }

    private static function logger(): LoggerInterface
    {
        return GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)
            ->getLogger(__CLASS__);
    }
}
