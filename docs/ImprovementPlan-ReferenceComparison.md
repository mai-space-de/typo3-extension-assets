# Improvement Plan: Reference Comparison with `praetorius/vite-asset-collector`

> **Reference checkout:** `.lookup/vite-asset-collector/` (v1.18.1)
> **Local extension:** `packages/typo3-extension-assets/` (mai_assets)
> **Date:** 2026-08-06
> **Purpose:** Identify and implement improvements inspired by the reference Vite integration to enhance `mai_assets`' capabilities, security, and TYPO3 ecosystem integration.

---

## Executive Summary

`mai_assets` is already a **significantly more comprehensive** asset pipeline than the reference implementation. The reference is a focused, specialized Vite integration (~12 classes), while `mai_assets` is a complete asset pipeline (~80 classes) with:

- Full SCSS compilation pipeline with dependency-aware caching
- Critical CSS with browser-based above-fold detection
- HTTP 103 Early Hints with hybrid delivery fallback
- Static file caching with Brotli/Gzip compression
- Responsive images with AVIF/WebP generation
- SVG sprite system with symbol injection
- Video ViewHelper with privacy-friendly facades
- SRI hash computation
- Backend reporting module
- Cache invalidation orchestration
- Readiness gating and warm-up model

The improvements below add **missing features from the reference** that would enhance `mai_assets` in specific areas: CSP handling, Icon API integration, configuration flexibility, and code organization.

---

## Priority Classification

| Priority | Count | Focus |
|----------|-------|-------|
| **Critical** | 5 | Security, dev experience, TYPO3 ecosystem integration |
| **Medium** | 5 | Performance, consistency, code organization |
| **Low** | 5 | Maintenance, testing, future features |

---

## Critical Improvements (High Priority)

### 1. CSP Nonce Meta Tag Middleware

**Reference:** `Classes/Middleware/AddCspNonceMetaTag.php`

**Problem:** `mai_assets` ViewHelpers accept `nonce` arguments but lack the middleware that injects `<meta property="csp-nonce">` into `<head>` for CSP-compliant inline scripts (e.g., Vite dev server, above-fold observer).

**Solution:** Add a middleware that runs after `typo3/cms-frontend/csp-headers` and injects the nonce meta tag when a CSP nonce is available.

**Implementation:**

```php
<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class AddCspNonceMetaTagMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $nonceAttribute = $request->getAttribute('nonce');
        if (!$nonceAttribute instanceof ConsumableNonce) {
            return $handler->handle($request);
        }

        $nonce = $nonceAttribute->consume();
        $this->pageRenderer->addHeaderData(
            '<meta property="csp-nonce" nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '">'
        );

        return $handler->handle($request);
    }
}
```

**Registration:**

```php
// Configuration/RequestMiddlewares.php
'frontend' => [
    'maispace/mai-assets/add-csp-nonce-meta-tag' => [
        'target' => \Maispace\MaiAssets\Middleware\AddCspNonceMetaTagMiddleware::class,
        'after' => [
            'typo3/cms-frontend/csp-headers',
        ],
    ],
],
'backend' => [
    'maispace/mai-assets/add-csp-nonce-meta-tag' => [
        'target' => \Maispace\MaiAssets\Middleware\AddCspNonceMetaTagMiddleware::class,
        'after' => [
            'typo3/cms-backend/csp-headers',
        ],
    ],
],
```

**Impact:** Enables CSP-compliant inline scripts in development contexts (e.g., Vite dev server, above-fold observer script).

**Effort:** Low (1 class, 1 middleware registration)

---

### 2. CSP Mutation Event Listener

**Reference:** `Classes/EventListener/MutateContentSecurityPolicy.php`

**Problem:** Development environments (e.g., Vite dev server on `localhost:5173`) require CSP exceptions for `connect-src`, `script-src`, `style-src`, `font-src`, and `img-src`. Without automatic CSP mutation, developers must manually configure CSP or disable it entirely.

**Solution:** Add an event listener that extends CSP to allow dev server connections when `Environment::getContext()->isDevelopment()`.

**Implementation:**

```php
<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceKeyword;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;

#[AsEventListener(identifier: 'mai-assets/csp-mutation')]
final class MutateContentSecurityPolicyListener
{
    public function __invoke(PolicyMutatedEvent $event): void
    {
        if (!Environment::getContext()->isDevelopment()) {
            return;
        }

        // Allow dev server URLs (make configurable via ExtensionConfiguration)
        $devServerUri = 'http://localhost:5173';
        $uris = [
            new UriValue($devServerUri),
            new UriValue(str_replace('http://', 'https://', $devServerUri)),
            new UriValue(str_replace('http://', 'wss://', $devServerUri)),
        ];

        $event->getCurrentPolicy()->extend(Directive::ConnectSrc, ...$uris);
        $event->getCurrentPolicy()->extend(Directive::ScriptSrcElem, ...$uris);
        $event->getCurrentPolicy()->extend(Directive::StyleSrcElem, ...$uris);
        $event->getCurrentPolicy()->extend(Directive::FontSrc, ...$uris);
        $event->getCurrentPolicy()->extend(Directive::ImgSrc, ...$uris);

        // Ensure nonces are allowed for script and style tags
        if (!$event->getCurrentPolicy()->containsDirective(Directive::ScriptSrcElem, SourceKeyword::unsafeInline)) {
            $event->getCurrentPolicy()->extend(Directive::ScriptSrcElem, SourceKeyword::nonceProxy);
        }
        if (!$event->getCurrentPolicy()->containsDirective(Directive::StyleSrcElem, SourceKeyword::unsafeInline)) {
            $event->getCurrentPolicy()->extend(Directive::StyleSrcElem, SourceKeyword::nonceProxy);
        }
    }
}
```

**Impact:** Improves developer experience by automatically handling CSP for dev server connections.

**Effort:** Low (1 class, 1 event listener registration)

---

### 3. Placeholder Processor for Asset Paths

**Reference:** `Classes/Configuration/VitePlaceholderProcessor.php`

**Problem:** TYPO3 YAML configuration (e.g., `SiteConfiguration`, `services.yaml`) cannot reference compiled asset paths. Developers must hardcode paths or use workarounds.

**Solution:** Add a placeholder processor that resolves `%mai_asset(path/to/file.css)` to the compiled public URL.

**Implementation:**

```php
<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Configuration;

use Maispace\MaiAssets\Service\CompiledAssetPublisher;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Configuration\Processor\Placeholder\PlaceholderProcessorInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

#[Autoconfigure(public: true)]
final class AssetPlaceholderProcessor implements PlaceholderProcessorInterface
{
    public const PLACEHOLDER_PATTERN = '^[\'"]?([^(]*?)[\'"]?$';

    public function __construct(
        private readonly CompiledAssetPublisher $compiledAssetPublisher,
    ) {}

    public function canProcess(string $placeholder, array $referenceArray): bool
    {
        return str_starts_with($placeholder, '%mai_asset(');
    }

    public function process(string $value, array $referenceArray): string
    {
        preg_match('/' . self::PLACEHOLDER_PATTERN . '/', $value, $matches);
        if (empty($matches)) {
            return '';
        }

        $assetFile = $matches[1];
        $absolutePath = GeneralUtility::getFileAbsFileName($assetFile);

        if ($absolutePath === '' || !file_exists($absolutePath)) {
            return '';
        }

        $compiledPath = $this->compiledAssetPublisher->publishStylesheet($absolutePath);
        return PathUtility::getAbsoluteWebPath($compiledPath);
    }
}
```

**Registration:**

```php
// ext_localconf.php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['yamlLoader']['placeholderProcessors'][\Maispace\MaiAssets\Configuration\AssetPlaceholderProcessor::class] = [];
```

**Usage:**

```yaml
# SiteConfiguration/config.yaml
settings:
  cssFile: '%mai_asset(EXT:mai_theme/Resources/Public/Scss/main.scss)%'
```

**Impact:** Enables dynamic asset path resolution in YAML configuration.

**Effort:** Medium (1 class, 1 registration, testing)

---

### 4. TYPO3 Icon API Integration

**Reference:** `Classes/IconProvider/SvgIconProvider.php`

**Problem:** `mai_assets` has SVG ViewHelpers (`<mai:svg.icon>`, `<mai:svg.inline>`) but no integration with TYPO3's Icon API. Backend modules cannot use `mai_assets` SVG icons via `$iconFactory->getIcon()`.

**Solution:** Add an Icon Provider that integrates with TYPO3's Icon API and uses the SVG sprite system.

**Implementation:**

```php
<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\IconProvider;

use Maispace\MaiAssets\Collector\SvgSpriteCollector;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconProvider\AbstractSvgIconProvider;

#[Autoconfigure(public: true)]
class MaiSvgIconProvider extends AbstractSvgIconProvider
{
    public function __construct(
        private readonly SvgSpriteCollector $svgSpriteCollector,
    ) {}

    protected function generateMarkup(Icon $icon, array $options): string
    {
        if (empty($options['source'])) {
            throw new \InvalidArgumentException(
                '[' . $icon->getIdentifier() . '] The option "source" is required'
            );
        }

        $identifier = $options['identifier'] ?? $icon->getIdentifier();
        $source = $options['source'];

        // Register the SVG in the sprite
        $this->svgSpriteCollector->register($identifier, $source);

        return '<svg aria-hidden="true" focusable="false" width="' . $icon->getDimension()->getWidth() . '" height="' . $icon->getDimension()->getHeight() . '">'
            . '<use href="#' . htmlspecialchars($identifier, ENT_QUOTES) . '"/>'
            . '</svg>';
    }

    protected function generateInlineMarkup(array $options): string
    {
        if (empty($options['source'])) {
            throw new \InvalidArgumentException('The option "source" is required');
        }

        $source = $options['source'];
        $svgContent = file_get_contents($source);

        // Strip XML declaration and dimensions
        $svgContent = preg_replace('/<\?xml[^?]*\?>\s*/i', '', $svgContent) ?? $svgContent;
        $svgContent = preg_replace('/\s(width|height)="[^"]*"/i', '', $svgContent) ?? $svgContent;

        return $svgContent;
    }
}
```

**Usage:**

```php
// Icons.php
return [
    'mai-assets-custom-icon' => [
        'provider' => \Maispace\MaiAssets\IconProvider\MaiSvgIconProvider::class,
        'source' => 'EXT:mai_theme/Resources/Public/Icons/custom.svg',
    ],
];

// Backend module
$icon = $iconFactory->getIcon('mai-assets-custom-icon', Icon::SIZE_SMALL);
```

**Impact:** Enables `mai_assets` SVG icons in backend modules via the Icon API.

**Effort:** Medium (1 class, documentation, testing)

---

### 5. Language Update Exclusion

**Reference:** `Classes/EventListener/ExcludeExtensionFromLanguageUpdate.php`

**Problem:** TYPO3's language update mechanism attempts to download language packs for all installed extensions. `mai_assets` has no translatable labels (all labels are in backend module templates), so language pack downloads are unnecessary.

**Solution:** Add an event listener that excludes `mai_assets` from language pack updates.

**Implementation:**

```php
<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Install\Service\Event\ModifyLanguagePacksEvent;

#[AsEventListener(identifier: 'mai-assets/exclude-from-language-update')]
final readonly class ExcludeExtensionFromLanguageUpdateListener
{
    public function __invoke(ModifyLanguagePacksEvent $event): void
    {
        $event->removeExtension('mai_assets');
    }
}
```

**Impact:** Reduces unnecessary language pack downloads during `typo3 language:update`.

**Effort:** Low (1 class, 1 event listener registration)

---

## Medium Priority Improvements

### 6. External Flag for TYPO3 v13+

**Reference:** `Classes/Service/ViteService.php` (lines 75, 159)

**Problem:** TYPO3 v13+ introduced the `external` flag for `AssetCollector`, which bypasses path preparation and cache-busting parameters. This improves performance for hashed assets (no duplicate requests, no unnecessary cache-busting).

**Solution:** Detect TYPO3 version and add the `external` flag when appropriate.

**Implementation:**

```php
// In CssViewHelper::render() and JsViewHelper::render()
$typo3Version = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Information\Typo3Version::class);
$assetOptions = ['priority' => $priority];

if ($typo3Version->getMajorVersion() >= 13) {
    $assetOptions['external'] = true;
}

$this->assetCollector->addStyleSheet($identifier, $publicPath, $tagAttributes, $assetOptions);
```

**Impact:** Improves performance for compiled assets in TYPO3 v13+.

**Effort:** Low (modify 2 ViewHelpers)

---

### 7. Script/CSS Attribute Normalization

**Reference:** `Classes/Service/ViteService.php` (lines 342-358)

**Problem:** Boolean HTML attributes (`async`, `defer`, `disabled`) should be rendered as `async="async"` for XHTML compliance, but developers may pass `true` or `1`. The reference normalizes these attributes.

**Solution:** Add a utility class that normalizes boolean attributes.

**Implementation:**

```php
<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Utility;

final readonly class AttributeUtility
{
    public static function normalizeScriptAttributes(array $attributes): array
    {
        foreach (['async', 'defer', 'nomodule'] as $attr) {
            if (!empty($attributes[$attr])) {
                $attributes[$attr] = $attr;
            }
        }
        return $attributes;
    }

    public static function normalizeCssAttributes(array $attributes): array
    {
        if (!empty($attributes['disabled'])) {
            $attributes['disabled'] = 'disabled';
        }
        return $attributes;
    }
}
```

**Usage:**

```php
// In JsViewHelper::render()
$tagAttributes = AttributeUtility::normalizeScriptAttributes($tagAttributes);

// In CssViewHelper::render()
$tagAttributes = AttributeUtility::normalizeCssAttributes($tagAttributes);
```

**Impact:** Ensures consistent HTML output for boolean attributes.

**Effort:** Low (1 utility class, modify 2 ViewHelpers)

---

### 8. CSS/JS File Detection Utility

**Reference:** `Classes/Utility/VitePathUtility.php`

**Problem:** Multiple classes need to detect whether a file is CSS or JS. Currently, this logic is duplicated or inline.

**Solution:** Add a centralized utility class for file type detection.

**Implementation:**

```php
<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Utility;

final readonly class AssetPathUtility
{
    public static function isCssFile(string $fileName): bool
    {
        return preg_match('/\.(css|less|sass|scss|styl|stylus|pcss|postcss)$/i', $fileName) === 1;
    }

    public static function isJsFile(string $fileName): bool
    {
        return preg_match('/\.(js|mjs|ts|tsx|jsx)$/i', $fileName) === 1;
    }

    public static function isImageFile(string $fileName): bool
    {
        return preg_match('/\.(avif|webp|jpg|jpeg|png|gif|svg)$/i', $fileName) === 1;
    }

    public static function isFontFile(string $fileName): bool
    {
        return preg_match('/\.(woff2?|ttf|otf|eot)$/i', $fileName) === 1;
    }
}
```

**Impact:** Centralizes file type detection logic, reduces duplication.

**Effort:** Low (1 utility class)

---

### 9. Improved Extension Path Resolution

**Reference:** `Classes/Service/ViteService.php` (lines 272-296)

**Problem:** `FileResolutionTrait` resolves `EXT:` paths but does not handle symlinks or provide detailed error messages.

**Solution:** Enhance `FileResolutionTrait` with better error handling and symlink support.

**Implementation:**

```php
<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Traits;

use Maispace\MaiAssets\Exception\AssetFileNotFoundException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

trait FileResolutionTrait
{
    protected function resolveFilePath(string $path): string
    {
        if (str_starts_with($path, 'EXT:')) {
            $resolved = GeneralUtility::getFileAbsFileName($path);
            if ($resolved === '') {
                throw new AssetFileNotFoundException(
                    sprintf('Cannot resolve EXT: path: "%s". Extension may not be installed.', $path),
                    1700000020
                );
            }
            return $resolved;
        }

        if (!PathUtility::isAbsolutePath($path)) {
            $path = \TYPO3\CMS\Core\Core\Environment::getPublicPath() . '/' . $path;
        }

        return $path;
    }

    protected function resolveFilePathWithSymlinks(string $path): string
    {
        $resolved = $this->resolveFilePath($path);

        // Resolve symlinks to get the real path
        $realPath = realpath($resolved);
        if ($realPath === false) {
            throw new AssetFileNotFoundException(
                sprintf('File does not exist or is not accessible: "%s"', $resolved),
                1700000021
            );
        }

        return $realPath;
    }

    protected function fileExists(string $path): bool
    {
        try {
            $resolved = $this->resolveFilePath($path);
            return file_exists($resolved);
        } catch (AssetFileNotFoundException) {
            return false;
        }
    }

    protected function requireFile(string $path): string
    {
        $resolved = $this->resolveFilePath($path);
        if (!file_exists($resolved)) {
            throw new AssetFileNotFoundException(
                sprintf('Required file not found: "%s" (resolved: "%s")', $path, $resolved),
                1700000001
            );
        }
        return $resolved;
    }
}
```

**Impact:** Better error messages and symlink support for extension paths.

**Effort:** Low (enhance existing trait)

---

### 10. ViewHelper Deprecation Handling

**Reference:** `Classes/ViewHelpers/AssetViewHelper.php` (lines 141-149)

**Problem:** Future ViewHelper renames or API changes need a deprecation mechanism to warn developers.

**Solution:** Implement `ViewHelperNodeInitializedEventInterface` for deprecation warnings.

**Implementation:**

```php
<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Asset;

use TYPO3Fluid\Fluid\Core\Parser\ParsingState;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\NodeInterface;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ViewHelperNode;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperNodeInitializedEventInterface;

class CssViewHelper extends AbstractViewHelper implements ViewHelperNodeInitializedEventInterface
{
    // ... existing code ...

    public static function nodeInitializedEvent(ViewHelperNode $node, array $arguments, ParsingState $parsingState): void
    {
        // Example: if we rename <mai:stylesheet> to <mai:css> in the future
        if ($node->getName() === 'stylesheet') {
            trigger_error(
                'ViewHelper <mai:stylesheet> has been renamed to <mai:css>. The old name is deprecated and will be removed in mai_assets 2.0.',
                E_USER_DEPRECATED
            );
        }
    }
}
```

**Impact:** Provides a migration path for future API changes.

**Effort:** Low (add interface and method to ViewHelpers)

---

## Low Priority Improvements

### 11. Transient Memory Cache for Compiled Assets

**Reference:** `ext_localconf.php` (lines 10-13)

**Problem:** Compiled assets are cached in `typo3temp/` with file-based caching. For frequently accessed assets, in-memory caching (per-request) would be faster.

**Solution:** Add a transient memory cache for compiled asset paths.

**Implementation:**

```php
// ext_localconf.php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mai_assets_compiled'] = [
    'backend' => \TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend::class,
];
```

**Usage:**

```php
// In CompiledAssetPublisher
public function publishStylesheet(string $absoluteSourcePath, ?bool $minify = null): string
{
    $cacheKey = $this->getCacheKey($absoluteSourcePath, $compileScss, $shouldMinify);
    
    $cached = $this->cache->get($cacheKey);
    if ($cached !== false) {
        return $cached;
    }

    // ... compile and cache ...
    
    $this->cache->set($cacheKey, $cacheFile);
    return $cacheFile;
}
```

**Impact:** Faster asset path resolution for frequently accessed assets.

**Effort:** Medium (modify CompiledAssetPublisher, add cache configuration)

---

### 12. Recursive Import Resolution for Assets

**Reference:** `Classes/Domain/Model/ViteManifest.php` (lines 38-61)

**Problem:** `ScssDependencyHasher` resolves SCSS imports but does not provide a public API for querying the dependency tree.

**Solution:** Add a public method to `ScssDependencyHasher` that returns the full dependency tree.

**Implementation:**

```php
// In ScssDependencyHasher
/**
 * @return list<string> List of absolute file paths in the dependency tree
 */
public function getDependencyTree(string $absoluteSourcePath): array
{
    $tree = [];
    $queue = [$absoluteSourcePath];
    $seen = [];

    while ($queue !== []) {
        $path = array_shift($queue);
        $real = realpath($path) ?: $path;
        
        if (isset($seen[$real])) {
            continue;
        }
        $seen[$real] = true;

        if (!is_file($real)) {
            continue;
        }

        $tree[] = $real;

        if (!str_ends_with(strtolower($real), '.scss')) {
            continue;
        }

        foreach ($this->collectImports($real) as $importPath) {
            $queue[] = $importPath;
        }
    }

    return $tree;
}
```

**Impact:** Enables tooling to analyze SCSS dependency trees.

**Effort:** Low (add method to existing class)

---

### 13. Auto-Generated ViewHelper Documentation

**Reference:** `composer.json` (line 98)

**Problem:** ViewHelper documentation is manually maintained in `README.md` and may become outdated.

**Solution:** Add a composer script to auto-generate ViewHelper documentation from PHPDoc.

**Implementation:**

```json
// composer.json
{
    "scripts": {
        "docs:viewhelpers": "typo3 fluid:documentation:generate --extension=mai_assets --output=Documentation/ViewHelpers/"
    }
}
```

**Impact:** Keeps ViewHelper documentation in sync with code.

**Effort:** Low (add composer script, requires `t3docs/fluid-documentation-generator`)

---

### 14. Improve Test Coverage

**Reference:** `Tests/` directory structure

**Problem:** `mai_assets` has good test coverage but some components lack comprehensive tests.

**Solution:** Add unit tests for:
- `FontPreloadCollector`
- `SvgSpriteCollector`
- `ScssProcessor`
- `MinificationProcessor`
- `CompressionProcessor`
- All ViewHelpers (currently only some have tests)
- All Event classes

**Impact:** Improves code quality and reduces regression risk.

**Effort:** High (write tests for ~20 classes)

---

### 15. Dev Server Mode (Future)

**Reference:** `Classes/Service/ViteService.php` (lines 39-107)

**Problem:** Development environments require manual asset compilation. Vite dev server provides HMR (Hot Module Replacement) for instant feedback.

**Solution:** Add dev server support with:
- Dev server detection (environment variable or configuration)
- Dev server URL configuration
- Dev server asset injection (bypass compilation, use dev server URLs)

**Implementation:**

```php
// ExtensionConfiguration
public function useDevServer(): bool
{
    $useDevServer = $this->extensionConfiguration->get('mai_assets', 'useDevServer');
    if ($useDevServer === 'auto') {
        return Environment::getContext()->isDevelopment();
    }
    return (bool)$useDevServer;
}

public function getDevServerUri(): string
{
    return $this->extensionConfiguration->get('mai_assets', 'devServerUri')
        ?: 'http://localhost:5173';
}
```

**Usage:**

```php
// In CssViewHelper
if ($this->extensionConfiguration->useDevServer()) {
    $devServerUri = $this->extensionConfiguration->getDevServerUri();
    $publicPath = $devServerUri . '/' . ltrim($src, '/');
    $this->assetCollector->addStyleSheet($identifier, $publicPath, $tagAttributes, $assetOptions);
    return '';
}
```

**Impact:** Improves developer experience with HMR and instant feedback.

**Effort:** High (requires Vite integration, configuration, testing)

---

## Implementation Roadmap

### Phase 1: Critical Improvements (Week 1-2)

1. **CSP Nonce Meta Tag Middleware** (1 day)
   - Implement `AddCspNonceMetaTagMiddleware`
   - Register in `RequestMiddlewares.php`
   - Add unit tests

2. **CSP Mutation Event Listener** (1 day)
   - Implement `MutateContentSecurityPolicyListener`
   - Register in `Services.yaml`
   - Add functional tests

3. **Language Update Exclusion** (0.5 day)
   - Implement `ExcludeExtensionFromLanguageUpdateListener`
   - Register in `Services.yaml`

4. **Placeholder Processor** (2 days)
   - Implement `AssetPlaceholderProcessor`
   - Register in `ext_localconf.php`
   - Add functional tests

5. **Icon API Integration** (2 days)
   - Implement `MaiSvgIconProvider`
   - Add documentation
   - Add functional tests

### Phase 2: Medium Improvements (Week 3)

6. **External Flag for v13+** (0.5 day)
   - Modify `CssViewHelper` and `JsViewHelper`
   - Add version detection

7. **Attribute Normalization** (0.5 day)
   - Implement `AttributeUtility`
   - Modify ViewHelpers

8. **File Detection Utility** (0.5 day)
   - Implement `AssetPathUtility`
   - Refactor existing code to use it

9. **Improved Path Resolution** (1 day)
   - Enhance `FileResolutionTrait`
   - Add tests

10. **ViewHelper Deprecation** (0.5 day)
    - Add `ViewHelperNodeInitializedEventInterface` to ViewHelpers

### Phase 3: Low Improvements (Week 4+)

11. **Transient Memory Cache** (1 day)
12. **Recursive Import Resolution** (0.5 day)
13. **Auto-Generated Documentation** (0.5 day)
14. **Test Coverage** (3-5 days)
15. **Dev Server Mode** (5-7 days, future)

---

## Testing Strategy

### Unit Tests

- All new classes should have unit tests
- Mock dependencies (e.g., `CompiledAssetPublisher`, `SvgSpriteCollector`)
- Test edge cases (empty inputs, invalid paths, missing files)

### Functional Tests

- Middleware integration tests (CSP nonce, CSP mutation)
- Placeholder processor tests (YAML parsing, path resolution)
- Icon provider tests (Icon API integration)

### Manual Testing

- Test CSP nonce meta tag in browser with CSP enabled
- Test placeholder processor in YAML configuration
- Test Icon API integration in backend module
- Test dev server mode (when implemented)

---

## Migration Guide

### For Existing Users

No breaking changes. All improvements are additive and backward-compatible.

### For Contributors

1. Follow existing code style (PSR-12, TYPO3 coding standards)
2. Add tests for all new code
3. Update `CHANGELOG.md` with changes
4. Update `FEATURES.md` if adding new features
5. Run `composer lint:check` and `composer test` before submitting

---

## Success Criteria

- [ ] All critical improvements implemented and tested
- [ ] All medium improvements implemented and tested
- [ ] Test coverage > 80% for new code
- [ ] Documentation updated (README, FEATURES, CHANGELOG)
- [ ] No PHPStan errors
- [ ] No PHPCS errors
- [ ] Manual testing in DDEV environment

---

## References

- **Reference implementation:** `.lookup/vite-asset-collector/` (v1.18.1)
- **TYPO3 CSP documentation:** https://docs.typo3.org/m/typo3/reference-core/main/en-us/ApiOverview/ContentSecurityPolicy/_Index.html
- **TYPO3 Icon API:** https://docs.typo3.org/m/typo3/reference-core/main/en-us/ApiOverview/Icons/_Index.html
- **TYPO3 AssetCollector:** https://docs.typo3.org/m/typo3/reference-core/main/en-us/ApiOverview/AssetCollector/_Index.html

---

## Appendix: Comparison Matrix

| Feature | `mai_assets` | `vite-asset-collector` | Gap |
|---------|--------------|------------------------|-----|
| SCSS compilation | ✅ | ❌ | — |
| CSS/JS minification | ✅ | ❌ | — |
| Critical CSS | ✅ | ❌ | — |
| Above-fold detection | ✅ | ❌ | — |
| HTTP 103 Early Hints | ✅ | ❌ | — |
| Static file caching | ✅ | ❌ | — |
| Responsive images | ✅ | ❌ | — |
| SVG sprites | ✅ | ❌ | — |
| Video facades | ✅ | ❌ | — |
| SRI hashes | ✅ | ❌ | — |
| Backend reporting | ✅ | ❌ | — |
| CSP nonce meta tag | ❌ | ✅ | **Gap** |
| CSP mutation listener | ❌ | ✅ | **Gap** |
| Placeholder processor | ❌ | ✅ | **Gap** |
| Icon API integration | ❌ | ✅ | **Gap** |
| Language update exclusion | ❌ | ✅ | **Gap** |
| External flag (v13+) | ❌ | ✅ | **Gap** |
| Attribute normalization | ❌ | ✅ | **Gap** |
| File detection utility | ❌ | ✅ | **Gap** |
| Dev server mode | ❌ | ✅ | **Gap (future)** |

---

**Document version:** 1.0
**Last updated:** 2026-08-06
**Author:** OpenCode Agent (opencode-deepseek)
