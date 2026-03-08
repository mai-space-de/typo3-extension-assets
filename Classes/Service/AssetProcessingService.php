<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Service;

use Maispace\MaispaceAssets\Cache\AssetCacheManager;
use Maispace\MaispaceAssets\Event\AfterCssProcessedEvent;
use Maispace\MaispaceAssets\Event\AfterJsProcessedEvent;
use Maispace\MaispaceAssets\Event\AfterScssCompiledEvent;
use Maispace\MaispaceAssets\Exception\AssetCompilationException;
use Maispace\MaispaceAssets\Exception\AssetFileNotFoundException;
use Maispace\MaispaceAssets\Exception\AssetWriteException;
use MatthiasMullie\Minify;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Central service for processing CSS, JS, and SCSS assets.
 *
 * All ViewHelpers delegate to the static entry points here (handleCss, handleJs, handleScss).
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
    public function __construct(
        private readonly AssetCacheManager $cache,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly AssetCollector $collector,
        private readonly LoggerInterface $logger,
        private readonly ScssCompilerService $scssCompiler,
    ) {
    }

    // -------------------------------------------------------------------------
    // Public entry points (called from ViewHelpers)
    // -------------------------------------------------------------------------

    /**
     * Process a CSS asset and register it with TYPO3's AssetCollector.
     *
     * @param ServerRequestInterface $request       The current PSR-7 request
     * @param array<string, mixed>   $arguments     ViewHelper arguments (identifier, src, priority, minify, inline, deferred, media)
     * @param string|null            $inlineContent Content captured from the ViewHelper's child nodes
     */
    public function handleCss(ServerRequestInterface $request, array $arguments, ?string $inlineContent = null): void
    {
        $srcArg = isset($arguments['src']) && is_string($arguments['src']) ? $arguments['src'] : null;
        $deferredArg = $arguments['deferred'] ?? null;
        $isDeferred = $this->resolveFlag($request, 'deferred', is_bool($deferredArg) ? $deferredArg : null, 'css');
        $isInline = isset($arguments['inline']) && is_bool($arguments['inline']) ? $arguments['inline'] : false;
        $isPriority = isset($arguments['priority']) && is_bool($arguments['priority']) ? $arguments['priority'] : false;
        $media = isset($arguments['media']) && is_string($arguments['media']) ? $arguments['media'] : 'all';

        // Warn about option combinations that are silently ignored when inline=true.
        if ($isInline) {
            $id = is_string($arguments['identifier'] ?? null) ? (string)$arguments['identifier'] : ($srcArg ?? 'unknown');
            if (!empty($arguments['integrity'])) {
                $this->logger->warning(
                    'maispace_assets: integrity="true" has no effect on inline CSS'
                    . ' (identifier: ' . $id . ').'
                    . ' SRI integrity is only supported for file-based <link> output.'
                    . ' Remove inline="true" to use integrity.',
                );
            }
            if ($media !== '' && $media !== 'all') {
                $this->logger->warning(
                    'maispace_assets: media="' . $media . '" has no effect on inline CSS'
                    . ' (identifier: ' . $id . ').'
                    . ' The media attribute is only applied to <link> tags.'
                    . ' Remove inline="true" to use media.',
                );
            }
            if ($isDeferred) {
                $this->logger->warning(
                    'maispace_assets: deferred="true" has no effect on inline CSS'
                    . ' (identifier: ' . $id . ').'
                    . ' Deferred loading only applies to file-based <link> output.'
                    . ' Remove inline="true" to use deferred loading.',
                );
            }
        }

        // 1. External URL — bypass all local file processing entirely.
        if ($srcArg !== null && $this->isExternalUrl($srcArg)) {
            $identifier = $this->buildIdentifier(
                $request,
                isset($arguments['identifier']) && is_string($arguments['identifier']) ? $arguments['identifier'] : null,
                $srcArg,
                $srcArg,
                'css'
            );
            $integrityAttrs = $this->buildIntegrityAttrsForExternal($arguments);
            if ($isDeferred) {
                $this->collector->addStyleSheet(
                    $identifier,
                    $srcArg,
                    array_filter([
                        'media'  => 'print',
                        'onload' => "this.media='" . addslashes($media) . "'",
                    ] + $integrityAttrs),
                    ['priority' => false],
                );
                $this->collector->addInlineStyleSheet(
                    $identifier . '_noscript',
                    '<noscript><link rel="stylesheet" href="' . htmlspecialchars($srcArg) . '"></noscript>',
                    [],
                    ['priority' => false],
                );
            } else {
                $this->collector->addStyleSheet(
                    $identifier,
                    $srcArg,
                    array_filter(['media' => $media] + $integrityAttrs),
                    ['priority' => $isPriority],
                );
            }

            return;
        }

        // 2. Resolve source content and determine whether it is file-based.
        [$content, $absoluteSrc, $isFileBased] = $this->resolveSource($srcArg, $inlineContent);

        if ($content === null) {
            return; // No content, nothing to do.
        }

        // 3. Build a stable identifier.
        $identifier = $this->buildIdentifier(
            $request,
            is_string($arguments['identifier'] ?? null) ? (string)$arguments['identifier'] : null,
            $srcArg,
            $content,
            'css',
        );

        // 4. Determine minification setting.
        $minifyArg = $arguments['minify'] ?? null;
        $shouldMinify = $this->resolveFlag($request, 'minify', is_bool($minifyArg) ? $minifyArg : null, 'css');

        // 5. Check cache.
        $cacheKey = $this->cache->buildCssKey($identifier, $shouldMinify);
        if ($this->cache->has($cacheKey)) {
            $cached = $this->cache->get($cacheKey);
            if (is_string($cached)) {
                $processed = $cached;
            } else {
                // Fallback to recompute if cache holds unexpected type
                $processed = $shouldMinify ? $this->minifyCss($content, $absoluteSrc) : $content;
                /** @var AfterCssProcessedEvent $event */
                $event = $this->dispatcher->dispatch(
                    new AfterCssProcessedEvent($identifier, $processed, $arguments),
                );
                $processed = $event->getProcessedCss();
                $this->cache->set($cacheKey, $processed, ['maispace_assets_css']);
            }
        } else {
            // 6. Minify if requested.
            $processed = $shouldMinify ? $this->minifyCss($content, $absoluteSrc) : $content;
            // 7. Dispatch event (listeners may modify $processed).
            /** @var AfterCssProcessedEvent $event */
            $event = $this->dispatcher->dispatch(
                new AfterCssProcessedEvent($identifier, $processed, $arguments),
            );
            $processed = $event->getProcessedCss();
            // 8. Store in cache.
            $this->cache->set($cacheKey, $processed, ['maispace_assets_css']);
        }

        // 9. Register with AssetCollector.
        $nonce = $this->resolveNonce($request, $arguments);

        if ($isInline) {
            $inlineAttrs = $nonce !== null ? ['nonce' => $nonce] : [];
            $this->collector->addInlineStyleSheet(
                $identifier,
                $processed,
                $inlineAttrs,
                ['priority' => $isPriority],
            );

            return;
        }

        // Write to typo3temp/ and get the public-relative path.
        $publicPath = $this->writeToTemp($request, $processed, $identifier, 'css');
        if ($publicPath === null) {
            $message = 'maispace_assets: Could not write CSS file for identifier "' . $identifier . '". Check write permissions on typo3temp/assets/.';
            $this->logger->error($message);

            throw new AssetWriteException($message);
        }

        // Build SRI integrity attributes when requested.
        $integrityAttrs = $this->buildIntegrityAttrs($arguments, $processed);

        if ($isDeferred) {
            // Deferred non-blocking load via media="print" onload swap trick.
            // The browser loads the stylesheet without blocking render, then the onload
            // handler switches media to "all", applying the styles.
            // A <noscript> fallback is appended via the deferred attribute handling below.
            $this->collector->addStyleSheet(
                $identifier,
                $publicPath,
                array_filter([
                    'media'  => 'print',
                    'onload' => "this.media='" . addslashes($media) . "'",
                ] + $integrityAttrs),
                ['priority' => false], // deferred CSS is never in <head>
            );
            // Noscript fallback — registered as a separate inline block.
            $this->collector->addInlineStyleSheet(
                $identifier . '_noscript',
                '<noscript><link rel="stylesheet" href="' . htmlspecialchars($publicPath) . '"></noscript>',
                [],
                ['priority' => false],
            );
        } else {
            $this->collector->addStyleSheet(
                $identifier,
                $publicPath,
                array_filter(['media' => $media] + $integrityAttrs),
                ['priority' => $isPriority],
            );
        }
    }

    /**
     * Process a JS asset and register it with TYPO3's AssetCollector.
     *
     * @param ServerRequestInterface $request       The current PSR-7 request
     * @param array<string, mixed>   $arguments     ViewHelper arguments (identifier, src, priority, minify, defer, async, type)
     * @param string|null            $inlineContent Content captured from the ViewHelper's child nodes
     */
    public function handleJs(ServerRequestInterface $request, array $arguments, ?string $inlineContent): void
    {
        $type = $arguments['type'] ?? null;
        $isImportMap = ($type === 'importmap');

        // importmap: always inline JSON — src is meaningless per spec.
        // Resolve content from inline children only; skip minification and defer.
        if ($isImportMap) {
            $content = trim((string)$inlineContent);
            if ($content === '') {
                return;
            }
            $identifier = $this->buildIdentifier(
                $request,
                is_string($arguments['identifier'] ?? null) ? (string)$arguments['identifier'] : null,
                null,
                $content,
                'js',
            );
            $nonce = $this->resolveNonce($request, $arguments);
            $inlineAttrs = array_filter([
                'type'  => 'importmap',
                'nonce' => $nonce,
            ]);
            $this->collector->addInlineJavaScript(
                $identifier,
                $content,
                $inlineAttrs,
                ['priority' => true], // importmaps must be in <head>, before any module scripts
            );

            return;
        }

        $srcArg = isset($arguments['src']) && is_string($arguments['src']) ? $arguments['src'] : null;
        $deferArg = $arguments['defer'] ?? null;
        $useDefer = $this->resolveFlag($request, 'defer', is_bool($deferArg) ? $deferArg : null, 'js');
        $useAsync = (bool)($arguments['async'] ?? false);
        $useNoModule = (bool)($arguments['nomodule'] ?? false);
        $isPriority = (bool)($arguments['priority'] ?? false);

        // nomodule scripts must NOT be deferred (legacy parsers execute them immediately).
        if ($useNoModule) {
            $useDefer = false;
            $useAsync = false;
        }

        // External URL — bypass all local file processing entirely.
        if ($srcArg !== null && $this->isExternalUrl($srcArg)) {
            $identifier = $this->buildIdentifier(
                $request,
                is_string($arguments['identifier'] ?? null) ? (string)$arguments['identifier'] : null,
                $srcArg,
                $srcArg,
                'js'
            );
            $integrityAttrs = $this->buildIntegrityAttrsForExternal($arguments);
            $attributes = array_filter([
                'defer'    => $useDefer ? 'defer' : null,
                'async'    => $useAsync ? 'async' : null,
                'nomodule' => $useNoModule ? 'nomodule' : null,
                'type'     => $type,
            ] + $integrityAttrs);
            $this->collector->addJavaScript(
                $identifier,
                $srcArg,
                $attributes,
                ['priority' => $isPriority],
            );

            return;
        }

        [$content, $absoluteSrc, $isFileBased] = $this->resolveSource($srcArg, $inlineContent);

        if ($content === null) {
            return;
        }

        $identifier = $this->buildIdentifier(
            $request,
            is_string($arguments['identifier'] ?? null) ? (string)$arguments['identifier'] : null,
            $srcArg,
            $content,
            'js'
        );
        $minifyArgJs = $arguments['minify'] ?? null;
        $shouldMinify = $this->resolveFlag($request, 'minify', is_bool($minifyArgJs) ? $minifyArgJs : null, 'js');

        $cacheKey = $this->cache->buildJsKey($identifier, $shouldMinify);
        if ($this->cache->has($cacheKey)) {
            $cached = $this->cache->get($cacheKey);
            if (is_string($cached)) {
                $processed = $cached;
            } else {
                $processed = $shouldMinify ? $this->minifyJs($content, $absoluteSrc) : $content;
                /** @var AfterJsProcessedEvent $event */
                $event = $this->dispatcher->dispatch(
                    new AfterJsProcessedEvent($identifier, $processed, $arguments),
                );
                $processed = $event->getProcessedJs();
                $this->cache->set($cacheKey, $processed, ['maispace_assets_js']);
            }
        } else {
            $processed = $shouldMinify ? $this->minifyJs($content, $absoluteSrc) : $content;

            /** @var AfterJsProcessedEvent $event */
            $event = $this->dispatcher->dispatch(
                new AfterJsProcessedEvent($identifier, $processed, $arguments),
            );
            $processed = $event->getProcessedJs();

            $this->cache->set($cacheKey, $processed, ['maispace_assets_js']);
        }

        $nonce = $this->resolveNonce($request, $arguments);

        // Inline JS.
        if ($srcArg === null) {
            $inlineAttrs = $nonce !== null ? ['nonce' => $nonce] : [];
            $this->collector->addInlineJavaScript(
                $identifier,
                $processed,
                $inlineAttrs,
                ['priority' => $isPriority],
            );

            return;
        }

        // File-based JS.
        $publicPath = $this->writeToTemp($request, $processed, $identifier, 'js');
        if ($publicPath === null) {
            $message = 'maispace_assets: Could not write JS file for identifier "' . $identifier . '". Check write permissions on typo3temp/assets/.';
            $this->logger->error($message);

            throw new AssetWriteException($message);
        }

        // Build SRI integrity attributes when requested.
        $integrityAttrs = $this->buildIntegrityAttrs($arguments, $processed);

        $attributes = array_filter([
            'defer'    => $useDefer ? 'defer' : null,
            'async'    => $useAsync ? 'async' : null,
            'nomodule' => $useNoModule ? 'nomodule' : null,
            'type'     => $type,
        ] + $integrityAttrs);

        $this->collector->addJavaScript(
            $identifier,
            $publicPath,
            $attributes,
            ['priority' => $isPriority],
        );
    }

    /**
     * Compile SCSS to CSS, then register the result as a CSS asset.
     *
     * @param ServerRequestInterface $request       The current PSR-7 request
     * @param array<string, mixed>   $arguments     ViewHelper arguments (identifier, src, priority, minify, inline, importPaths)
     * @param string|null            $inlineContent Raw SCSS captured from the ViewHelper's child nodes
     */
    public function handleScss(ServerRequestInterface $request, array $arguments, ?string $inlineContent): void
    {
        $src = isset($arguments['src']) && is_string($arguments['src']) ? $arguments['src'] : null;
        $isFileBased = $src !== null;

        if ($isFileBased) {
            $absoluteSrc = GeneralUtility::getFileAbsFileName($src);
            if ($absoluteSrc === '' || !is_file($absoluteSrc)) {
                $message = 'maispace_assets: SCSS file not found: "' . $src . '". Verify the EXT: path or public-relative path is correct.';
                $this->logger->warning($message);

                throw new AssetFileNotFoundException($message);
            }
            $rawScss = (string)file_get_contents($absoluteSrc);
            $fileMtime = (int)filemtime($absoluteSrc);
        } else {
            $rawScss = trim((string)$inlineContent);
            $absoluteSrc = null;
            $fileMtime = null;
        }

        if ($rawScss === '') {
            return;
        }

        $identifier = $this->buildIdentifier(
            $request,
            is_string($arguments['identifier'] ?? null) ? (string)$arguments['identifier'] : null,
            $src,
            $rawScss,
            'scss',
        );

        $minifyArgScss = $arguments['minify'] ?? null;
        $shouldMinify = $this->resolveFlag($request, 'minify', is_bool($minifyArgScss) ? $minifyArgScss : null, 'scss');

        $cacheKey = $this->cache->buildScssKey($identifier, $fileMtime);
        if ($this->cache->has($cacheKey)) {
            $compiledCss = $this->cache->get($cacheKey);
            if (!is_string($compiledCss)) {
                $compiledCss = '';
            }
        } else {
            // Parse additional import paths from the argument.
            $importPaths = [];
            $importPathsArgRaw = $arguments['importPaths'] ?? null;
            $importPathsArg = is_string($importPathsArgRaw) ? trim($importPathsArgRaw) : '';
            if ($importPathsArg !== '') {
                $importPaths = array_map('trim', explode(',', $importPathsArg));
            }

            // Add TypoScript default import paths.
            $tsDefaultRaw = $this->getTypoScriptSetting($request, 'scss.defaultImportPaths', '');
            $tsDefault = is_string($tsDefaultRaw) ? trim($tsDefaultRaw) : '';
            if ($tsDefault !== '') {
                $importPaths = array_merge(
                    $importPaths,
                    array_map('trim', explode(',', $tsDefault)),
                );
            }

            try {
                $compiledCss = $this->scssCompiler->compile(
                    $rawScss,
                    $importPaths,
                    $shouldMinify,
                    $absoluteSrc,
                );
            } catch (\ScssPhp\ScssPhp\Exception\SassException $e) {
                $message = 'maispace_assets: SCSS compilation failed for "' . $identifier . '": ' . $e->getMessage();
                $this->logger->error($message);

                throw new AssetCompilationException($message, 0, $e);
            }

            /** @var AfterScssCompiledEvent $event */
            $event = $this->dispatcher->dispatch(
                new AfterScssCompiledEvent($identifier, $rawScss, $compiledCss, $arguments),
            );
            $compiledCss = $event->getCompiledCss();

            $cacheLifetimeRaw = $this->getTypoScriptSetting($request, 'scss.cacheLifetime', 0);
            $lifetime = is_int($cacheLifetimeRaw) ? $cacheLifetimeRaw : 0;
            $this->cache->set($cacheKey, $compiledCss, ['maispace_assets_scss'], $lifetime);
        }

        // Re-use CSS registration logic with the compiled output.
        // Pass src=null so the CSS handler treats it as inline/computed content.
        $cssArguments = $arguments;
        unset($cssArguments['importPaths']);
        $cssArguments['src'] = null;

        // Write the compiled CSS and register it — bypass the CSS cache since SCSS has its own.
        $this->registerCompiledCss($request, (string)$compiledCss, $identifier, $cssArguments);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Register pre-compiled CSS (from SCSS) directly with the AssetCollector,
     * bypassing the CSS minification cache (SCSS is already handled above).
     *
     * @param array<string, mixed> $arguments
     */
    private function registerCompiledCss(
        ServerRequestInterface $request,
        string $css,
        string $identifier,
        array $arguments,
    ): void {
        $isInline = (bool)($arguments['inline'] ?? false);
        $isPriority = (bool)($arguments['priority'] ?? false);
        $deferredArg = $arguments['deferred'] ?? null;
        $isDeferred = $this->resolveFlag($request, 'deferred', is_bool($deferredArg) ? $deferredArg : null, 'css');
        $media = is_string($arguments['media'] ?? null) ? $arguments['media'] : 'all';

        // Warn about option combinations that are silently ignored when inline=true.
        if ($isInline) {
            if (!empty($arguments['integrity'])) {
                $this->logger->warning(
                    'maispace_assets: integrity="true" has no effect on inline SCSS'
                    . ' (identifier: ' . $identifier . ').'
                    . ' SRI integrity is only supported for file-based <link> output.'
                    . ' Remove inline="true" to use integrity.',
                );
            }
            if ($media !== '' && $media !== 'all') {
                $this->logger->warning(
                    'maispace_assets: media="' . $media . '" has no effect on inline SCSS'
                    . ' (identifier: ' . $identifier . ').'
                    . ' The media attribute is only applied to <link> tags.'
                    . ' Remove inline="true" to use media.',
                );
            }
            if ($isDeferred) {
                $this->logger->warning(
                    'maispace_assets: deferred="true" has no effect on inline SCSS'
                    . ' (identifier: ' . $identifier . ').'
                    . ' Deferred loading only applies to file-based <link> output.'
                    . ' Remove inline="true" to use deferred loading.',
                );
            }
        }

        $nonce = $this->resolveNonce($request, $arguments);

        if ($isInline) {
            $inlineAttrs = $nonce !== null ? ['nonce' => $nonce] : [];
            $this->collector->addInlineStyleSheet($identifier, $css, $inlineAttrs, ['priority' => $isPriority]);

            return;
        }

        $publicPath = $this->writeToTemp($request, $css, $identifier, 'css');
        if ($publicPath === null) {
            $message = 'maispace_assets: Could not write compiled SCSS for identifier "' . $identifier . '". Check write permissions on typo3temp/assets/.';
            $this->logger->error($message);

            throw new AssetWriteException($message);
        }

        $absolutePath = Environment::getPublicPath() . PathUtility::getAbsoluteWebPath($publicPath);

        $integrityAttrs = $this->buildIntegrityAttrs($arguments, $css);

        if ($isDeferred) {
            $this->collector->addStyleSheet(
                $identifier,
                $absolutePath,
                array_filter([
                    'media'  => 'print',
                    'onload' => "this.media='" . addslashes($media) . "'",
                ] + $integrityAttrs),
                ['priority' => false],
            );
            $this->collector->addInlineStyleSheet(
                $identifier . '_noscript',
                '<noscript><link rel="stylesheet" href="' . htmlspecialchars($publicPath) . '"></noscript>',
                [],
                ['priority' => false],
            );
        } else {
            $this->collector->addStyleSheet(
                $identifier,
                $absolutePath,
                array_filter(['media' => $media] + $integrityAttrs),
                ['priority' => $isPriority],
            );
        }
    }

    /**
     * Resolve the asset source content and absolute file path.
     *
     * Returns [content, absolutePath|null, isFileBased].
     * Returns [null, null, false] when both src and inlineContent are empty (no-op).
     *
     * External URLs (http/https/protocol-relative) are returned as [url, null, false]
     * so callers can detect them with isExternalUrl() and bypass local file processing.
     *
     * @return array{0: string|null, 1: string|null, 2: bool}
     *
     * @throws AssetFileNotFoundException when src points to a non-existent local file
     */
    private function resolveSource(?string $src, ?string $inlineContent): array
    {
        if ($src !== null) {
            // External URLs are passed through without file resolution.
            if ($this->isExternalUrl($src)) {
                return [$src, null, false];
            }

            $absolute = GeneralUtility::getFileAbsFileName($src);
            if ($absolute === '' || !is_file($absolute)) {
                $message = 'maispace_assets: Asset file not found: "' . $src . '". Verify the EXT: path or public-relative path is correct.';
                $this->logger->warning($message);

                throw new AssetFileNotFoundException($message);
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
     * Return true when the given src is an external URL (http, https, or protocol-relative //).
     */
    private function isExternalUrl(string $src): bool
    {
        return str_starts_with($src, 'http://') || str_starts_with($src, 'https://') || str_starts_with($src, '//');
    }

    /**
     * Build integrity + crossorigin attrs for external assets.
     *
     * For external assets we cannot compute SRI hashes at render time without fetching
     * the remote resource, which would introduce network latency and failure modes.
     * Instead, only a pre-computed hash string passed as `integrityValue` is accepted.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, string>
     */
    private function buildIntegrityAttrsForExternal(array $arguments): array
    {
        $integrityValue = $arguments['integrityValue'] ?? null;
        if (!is_string($integrityValue) || $integrityValue === '') {
            return [];
        }

        $crossorigin = is_string($arguments['crossorigin'] ?? null) && $arguments['crossorigin'] !== ''
            ? $arguments['crossorigin']
            : 'anonymous';

        return [
            'integrity'   => $integrityValue,
            'crossorigin' => $crossorigin,
        ];
    }

    /**
     * Build a stable, collision-resistant identifier string.
     *
     * When the user provides an explicit identifier, it is used as-is (prefixed
     * with 'maispace_' if no TypoScript prefix is configured).
     * Otherwise the identifier is derived from a hash of the source path or content,
     * so the same input always maps to the same identifier — preventing orphaned files.
     */
    private function buildIdentifier(
        ServerRequestInterface $request,
        ?string $explicit,
        ?string $src,
        string $content,
        string $type,
    ): string {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        $prefixRaw = $this->getTypoScriptSetting($request, $type . '.identifierPrefix', 'maispace_');
        $prefix = is_string($prefixRaw) ? $prefixRaw : 'maispace_';
        $hash = $src !== null ? md5($src) : md5($content);

        return $prefix . $type . '_' . $hash;
    }

    /**
     * Resolve a boolean flag by checking the ViewHelper argument first,
     * then falling back to the TypoScript setting.
     *
     * A null argument means "use TypoScript default".
     */
    private function resolveFlag(ServerRequestInterface $request, string $setting, ?bool $argumentValue, string $section): bool
    {
        if ($argumentValue !== null) {
            return $argumentValue;
        }

        return (bool)$this->getTypoScriptSetting($request, $section . '.' . $setting, false);
    }

    /**
     * Resolve the CSP nonce to attach to an inline <style> or <script> tag.
     *
     * Resolution order:
     *  1. Explicit `nonce` ViewHelper argument — use as-is.
     *  2. TYPO3's built-in per-request nonce (TYPO3 12.4+, when CSP is enabled in Install Tool).
     *     Accessed via the PSR-7 request's 'nonce' attribute.
     *  3. null — no nonce attribute is added.
     *
     * This means that when TYPO3's CSP is enabled, inline assets automatically receive the
     * correct nonce without any ViewHelper configuration. The explicit argument is an escape
     * hatch for custom nonce values (e.g. passed from a PSR-15 middleware).
     *
     * Note: the nonce is intentionally only applied to inline assets (inline="true" / no src).
     * External file assets use SRI `integrity` instead; they do not require a nonce.
     */
    /**
     * @param array<string, mixed> $arguments
     */
    private function resolveNonce(ServerRequestInterface $request, array $arguments): ?string
    {
        // 1. Explicit argument.
        $explicit = $arguments['nonce'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        // 2. TYPO3's built-in request nonce (TYPO3 12.4+).
        $nonceAttr = $request->getAttribute('nonce');
        if ($nonceAttr instanceof \TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce) {
            $nonce = (string)$nonceAttr; // implements Stringable
        } else {
            return null;
        }

        return $nonce !== '' ? $nonce : null;
    }

    /**
     * Build the `integrity` and `crossorigin` attributes for SRI when requested.
     *
     * When `$arguments['integrity']` is `true`, computes a SHA-384 hash of the processed
     * asset content and returns both attributes so they can be merged into the link/script attrs.
     *
     * @param array<string, mixed> $arguments ViewHelper arguments (integrity, crossorigin keys)
     * @param string               $content   The processed asset content to hash
     *
     * @return array<string, string> Empty array when integrity is not requested
     */
    private function buildIntegrityAttrs(array $arguments, string $content): array
    {
        if (empty($arguments['integrity'])) {
            return [];
        }

        $hash = base64_encode(hash('sha384', $content, true));
        $crossorigin = is_string($arguments['crossorigin'] ?? null) && $arguments['crossorigin'] !== ''
            ? $arguments['crossorigin']
            : 'anonymous';

        return [
            'integrity'   => 'sha384-' . $hash,
            'crossorigin' => $crossorigin,
        ];
    }

    /**
     * Minify CSS content using matthiasmullie/minify.
     *
     * When $absolutePath is provided, the minifier can fix relative URLs in the CSS.
     */
    private function minifyCss(string $css, ?string $absolutePath = null): string
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
    private function minifyJs(string $js, ?string $absolutePath = null): string
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
    private function writeToTemp(ServerRequestInterface $request, string $content, string $identifier, string $type): ?string
    {
        $defaultOutputDir = 'typo3temp/assets/maispace_assets/' . $type . '/';
        $outputDirSetting = $this->getTypoScriptSetting($request, $type . '.outputDir', $defaultOutputDir);

        $outputDir = trim(is_string($outputDirSetting) ? $outputDirSetting : $defaultOutputDir, '/');
        if ($outputDir === '') {
            $outputDir = trim($defaultOutputDir, '/');
        }

        $absoluteDir = Environment::getPublicPath() . '/' . $outputDir . '/';
        if (!is_dir($absoluteDir)) {
            GeneralUtility::mkdir_deep($absoluteDir);
        }

        $fileName = sha1($identifier) . '.' . $type;
        $absoluteFile = $absoluteDir . $fileName;

        if (GeneralUtility::writeFile($absoluteFile, $content, true) === false) {
            return null;
        }

        GeneralUtility::fixPermissions($absoluteFile);

        return $this->resolveWebPath($absoluteFile);
    }

    /**
     * Read a TypoScript setting from plugin.tx_maispace_assets.{dotPath}.
     *
     * Returns $default if the setting is not configured.
     *
     * Example: getTypoScriptSetting($request, 'css.minify', false)
     *   reads plugin.tx_maispace_assets.css.minify
     */
    private function getTypoScriptSetting(ServerRequestInterface $request, string $dotPath, mixed $default): mixed
    {
        /** @var \TYPO3\CMS\Core\TypoScript\FrontendTypoScript|null $frontendTypoScript */
        $frontendTypoScript = $request->getAttribute('frontend.typoscript');
        if ($frontendTypoScript === null) {
            return $default;
        }

        /** @var array<string, mixed> $setup */
        $setup = $frontendTypoScript->getSetupArray();
        $rootPlugin = $setup['plugin.'] ?? null;
        if (!is_array($rootPlugin)) {
            return $default;
        }
        $root = $rootPlugin['tx_maispace_assets.'] ?? null;
        if (!is_array($root)) {
            return $default;
        }

        $parts = explode('.', $dotPath);
        $node = $root;
        $lastIndex = count($parts) - 1;
        foreach ($parts as $i => $part) {
            if ($i === $lastIndex) {
                return $node[$part] ?? $default;
            }
            $next = $node[$part . '.'] ?? null;
            if (!is_array($next)) {
                return $default;
            }
            $node = $next;
        }

        return $default;
    }

    /**
     * Resolve an absolute filesystem path to a web-relative URL.
     *
     * Handles site-relative paths (including TYPO3_SITE_PATH) and ensures
     * no double slashes are introduced at the join point.
     */
    private function resolveWebPath(string $absolutePath): string
    {
        $publicPath = Environment::getPublicPath();
        if (str_starts_with($absolutePath, $publicPath)) {
            $relativePath = ltrim(substr($absolutePath, strlen($publicPath)), '/\\');
            $sitePath = Environment::isCli() ? '/' : (string)GeneralUtility::getIndpEnv('TYPO3_SITE_PATH');

            return $sitePath . $relativePath;
        }

        return PathUtility::getAbsoluteWebPath($absolutePath);
    }
}
