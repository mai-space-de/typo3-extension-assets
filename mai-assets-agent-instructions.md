# Agent Instructions: `mai_assets` TYPO3 Extension

## Identity

- **Composer Package:** `maispace/mai-assets`
- **Extension Key:** `mai_assets`
- **PSR Namespace:** `Maispace\MaiAssets`
- **TYPO3 Compatibility:** v13 + v14
- **PHP:** 8.2+
- **License:** MIT

---

## Mission

Build a TYPO3 extension that provides a unified, self-optimising asset pipeline. Every asset type — CSS, JavaScript, SVG sprites, images, fonts, and video — is served intelligently based on whether the content element it belongs to is **critical** (above the fold) or **deferred** (below the fold). The system detects criticality automatically via an IntersectionObserver reporting loop, caches results, and self-corrects when editors restructure page content.

---

## Directory Structure

```
mai_assets/
├── composer.json
├── ext_emconf.php
├── ext_localconf.php
├── ext_tables.php
├── Configuration/
│   ├── RequestMiddlewares.php
│   ├── Services.yaml
│   ├── TCA/
│   │   └── Overrides/
│   │       └── tt_content.php
│   └── TypoScript/
│       ├── setup.typoscript
│       └── constants.typoscript
├── Classes/
│   ├── Cache/
│   │   └── AboveFoldCacheService.php
│   ├── Collector/
│   │   ├── AssetCollectorInterface.php
│   │   ├── AbstractAssetCollector.php
│   │   ├── SvgSpriteCollector.php
│   │   └── FontPreloadCollector.php
│   ├── Configuration/
│   │   └── ExtensionConfiguration.php
│   ├── DataProcessing/
│   │   └── CriticalAssetDataProcessor.php
│   ├── Event/
│   │   ├── AfterSpriteBuiltEvent.php
│   │   ├── AfterCriticalUidsUpdatedEvent.php
│   │   ├── BeforeAssetInjectionEvent.php
│   │   ├── BeforeObserverScriptInjectedEvent.php
│   │   └── ModifyCriticalThresholdEvent.php
│   ├── Hook/
│   │   ├── AboveFoldObserverHook.php
│   │   ├── ContentElementSaveHook.php
│   │   └── SvgSpriteInjectionHook.php
│   ├── Middleware/
│   │   └── AboveFoldReportMiddleware.php
│   ├── Processing/
│   │   ├── AssetProcessorInterface.php
│   │   ├── AbstractAssetProcessor.php
│   │   ├── ScssProcessor.php
│   │   ├── MinificationProcessor.php
│   │   └── CompressionProcessor.php
│   ├── Service/
│   │   ├── CriticalDetectionService.php
│   │   ├── FontPreloadService.php
│   │   └── ImageVariantService.php
│   ├── Traits/
│   │   ├── CriticalAwareTrait.php
│   │   ├── CacheKeyTrait.php
│   │   └── FileResolutionTrait.php
│   └── ViewHelpers/
│       ├── Asset/
│       │   ├── CriticalStyleViewHelper.php
│       │   └── PreloadFontViewHelper.php
│       ├── Image/
│       │   └── ResponsiveImageViewHelper.php
│       ├── Svg/
│       │   └── IconViewHelper.php
│       └── Video/
│           └── VideoViewHelper.php
├── Resources/
│   ├── Private/
│   │   └── Templates/
│   │       └── (Fluid templates if needed)
│   └── Public/
│       └── JavaScript/
│           └── AboveFoldObserver.js
└── Documentation/
    ├── Index.rst
    ├── Introduction/
    │   └── Index.rst
    ├── Installation/
    │   └── Index.rst
    ├── Configuration/
    │   └── Index.rst
    ├── ViewHelpers/
    │   └── Index.rst
    ├── Events/
    │   └── Index.rst
    ├── Developer/
    │   └── Index.rst
    └── Changelog/
        └── Index.rst
```

---

## Step 1 — Scaffolding & Composer

### `composer.json`

```json
{
    "name": "maispace/mai-assets",
    "description": "Intelligent asset pipeline for TYPO3 with critical CSS, SVG sprites, responsive images, font preloading, and self-optimising above-fold detection.",
    "type": "typo3-cms-extension",
    "license": "MIT",
    "require": {
        "typo3/cms-core": "^12.4 || ^13.0",
        "scssphp/scssphp": "^1.12",
        "matthiasmullie/minify": "^1.3"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0"
    },
    "autoload": {
        "psr-4": {
            "Maispace\\MaiAssets\\": "Classes/"
        }
    },
    "extra": {
        "typo3/cms": {
            "extension-key": "mai_assets"
        }
    }
}
```

### `ext_emconf.php`

Define title, description, version, TYPO3 version constraints, and dependencies. State it depends on `typo3/cms-core`.

---

## Step 2 — Configuration API

### `Classes/Configuration/ExtensionConfiguration.php`

This is the single source of truth for all extension settings. Read from `$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets']`. Expose a clean typed API — no raw array access anywhere else in the codebase.

**Settings to expose:**

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enableScssProcessing` | bool | true | Compile SCSS on the fly |
| `enableMinification` | bool | true | Minify CSS and JS |
| `enableCompression` | bool | true | Gzip and Brotli output |
| `compressionLevel` | int | 6 | 1–9 |
| `enableBrotli` | bool | true | Brotli in addition to gzip |
| `criticalThresholdByColPos` | array | `[0 => 2, 1 => 0, 3 => 0]` | Elements per column treated as critical by default |
| `viewportBuckets` | array | `['mobile' => 768, 'tablet' => 1024, 'desktop' => PHP_INT_MAX]` | Breakpoint definitions |
| `svgStripAttributes` | array | `['id', 'class', 'style']` | Attributes stripped from SVG symbols |
| `fontPreloadFormats` | array | `['woff2']` | Font formats to preload |
| `observerRootMargin` | string | `'200px'` | IntersectionObserver rootMargin for video lazy load |
| `processingCacheLifetime` | int | `0` | Seconds, 0 = forever |

Provide a static `getInstance()` or inject via constructor DI. Use `ExtensionConfiguration` from TYPO3 core to read settings.

---

## Step 3 — Traits

### `Traits/CriticalAwareTrait.php`

Used by any class that needs to check or mark criticality.

```php
trait CriticalAwareTrait
{
    protected function isCritical(int $pageUid, int $elementUid): bool;
    protected function getCriticalUidsForPage(int $pageUid): array;
}
```

Require `AboveFoldCacheService` to be injected in the consuming class. The trait calls it — keeping the logic DRY across DataProcessors, ViewHelpers, and Hooks.

### `Traits/CacheKeyTrait.php`

```php
trait CacheKeyTrait
{
    protected function buildCacheKey(int $pageUid, string $bucket): string
    {
        return 'page_' . $pageUid . '_' . $bucket;
    }

    protected function buildResetKey(int $pageUid): string
    {
        return 'reset_' . $pageUid;
    }
}
```

### `Traits/FileResolutionTrait.php`

Resolve `EXT:` paths, check existence, return absolute paths. Used by ViewHelpers and Processors.

---

## Step 4 — Interfaces & Abstracts

### `Collector/AssetCollectorInterface.php`

```php
interface AssetCollectorInterface
{
    public function register(string $identifier, string $filePath): void;
    public function has(string $identifier): bool;
    public function getAll(): array;
    public function reset(): void;
}
```

### `Collector/AbstractAssetCollector.php`

Implements `AssetCollectorInterface`. Implements `SingletonInterface` (TYPO3). Provides deduplication via identifier map. Subclasses only implement `build(): string`.

### `Processing/AssetProcessorInterface.php`

```php
interface AssetProcessorInterface
{
    public function canProcess(string $filePath): bool;
    public function process(string $content, string $sourcePath): string;
}
```

### `Processing/AbstractAssetProcessor.php`

Implements `AssetProcessorInterface`. Provides caching of processed output keyed by file hash + settings hash. Calls `doProcess()` which subclasses implement. Emits `BeforeAssetInjectionEvent` before returning.

---

## Step 5 — Asset Processors

### `Processing/ScssProcessor.php`

- Extends `AbstractAssetProcessor`
- Uses `scssphp/scssphp` (`\ScssPhp\ScssPhp\Compiler`)
- `canProcess()` returns true for `.scss` files
- Resolves `@import` and `@use` relative to source file directory
- Sets import paths automatically
- Returns compiled CSS string
- Errors should throw `\RuntimeException` with file path and SCSS error message

### `Processing/MinificationProcessor.php`

- Extends `AbstractAssetProcessor`
- Uses `matthiasmullie/minify`
- `canProcess()` returns true for `.css` and `.js`
- Detects content type from extension and uses `Minify\CSS` or `Minify\JS`
- Preserves source maps option (configurable)

### `Processing/CompressionProcessor.php`

- Does not extend `AbstractAssetProcessor` (it operates on file output, not content strings)
- After writing processed asset to `typo3temp/assets/mai_assets/`
  - Write `.gz` using `gzencode()` at configured level
  - Write `.br` using `brotli_compress()` if `ext-brotli` available and `enableBrotli` is true
- Check `$_SERVER['HTTP_ACCEPT_ENCODING']` is **not** done here — that is the web server's job. Simply ensure the pre-compressed files exist alongside the original. Document the required nginx/Apache configuration in RST.

---

## Step 6 — Cache Service

### `Cache/AboveFoldCacheService.php`

Implements `SingletonInterface`. Uses CacheKeyTrait.

**Methods:**

```php
public function getCriticalUids(int $pageUid, string $bucket): array
public function getAllCriticalUids(int $pageUid): array  // all buckets merged
public function updateCriticalUids(int $pageUid, string $bucket, array $newUids): bool
public function clearCriticalUids(int $pageUid): void
public function getResetTimestamp(int $pageUid): int
public function bumpResetTimestamp(int $pageUid): void
```

`updateCriticalUids()` must:
1. Sort both new and existing arrays before comparing
2. Return `true` only if they differ
3. Store per bucket key
4. Dispatch `AfterCriticalUidsUpdatedEvent` with pageUid, bucket, old UIDs, new UIDs

Register cache identifier `mai_assets_above_fold` in `ext_localconf.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mai_assets_above_fold'] = [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend'  => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
    'groups'   => ['system'],
];
```

---

## Step 7 — Collectors

### `Collector/SvgSpriteCollector.php`

Extends `AbstractAssetCollector`. Implements `SingletonInterface`.

**`build(): string`**

- Iterate registered symbols
- Load SVG file via `file_get_contents()`
- Parse with `\SimpleXMLElement`
- Extract `viewBox` attribute
- Extract inner XML (all children concatenated)
- Strip configured attributes (`svgStripAttributes`)
- Wrap in `<symbol id="{identifier}" viewBox="{viewBox}">...</symbol>`
- Wrap all symbols in `<svg xmlns="..." style="display:none" aria-hidden="true">...</svg>`
- Dispatch `AfterSpriteBuiltEvent` with final sprite string (allows modification)

### `Collector/FontPreloadCollector.php`

Extends `AbstractAssetCollector`. Registers font file paths. `build()` returns array of `<link rel="preload">` tags. Uses `ExtensionConfiguration::getFontPreloadFormats()` to filter.

---

## Step 8 — Services

### `Service/CriticalDetectionService.php`

Encapsulates all criticality logic. Inject `AboveFoldCacheService` and `ExtensionConfiguration`.

```php
public function isCritical(int $pageUid, int $elementUid): bool
public function getThresholdForColPos(int $colPos): int
public function resolveBucketFromRequest(ServerRequestInterface $request): string
```

`resolveBucketFromRequest()` reads the `viewport_bucket` cookie from the request. Falls back to `'desktop'` if absent.

Dispatch `ModifyCriticalThresholdEvent` before returning threshold, allowing integrators to override per page type, backend layout, etc.

### `Service/FontPreloadService.php`

Wraps `FontPreloadCollector`. Provides `registerCriticalFont(string $path): void`. Called by `PreloadFontViewHelper`. The hook reads the collector and injects `<link rel="preload">` tags via `AssetCollector`.

### `Service/ImageVariantService.php`

Wraps TYPO3's `ImageService`. Given a FAL file and a breakpoints array, processes all format/width combinations and returns a structured array:

```php
[
    'mobile'  => ['avif' => '/url/img.avif', 'webp' => '/url/img.webp', 'jpeg' => '/url/img.jpg', 'width' => 400],
    'tablet'  => [...],
    'desktop' => [...],
]
```

Formats: `avif`, `webp`, `jpeg`. Order matters — AVIF first in `<source>` tags.

---

## Step 9 — Data Processor

### `DataProcessing/CriticalAssetDataProcessor.php`

Implements `DataProcessorInterface`. Inject `CriticalDetectionService`.

Sets on `$processedData`:

| Key | Type | Description |
|-----|------|-------------|
| `isCritical` | bool | Element is above fold |
| `loadingStrategy` | string | `'eager'` or `'lazy'` |
| `fetchPriority` | string | `'high'` or `'low'` |
| `decodingStrategy` | string | `'sync'` or `'async'` |
| `cssStrategy` | string | `'inline'` or `'deferred'` |

Register in TypoScript:

```typoscript
tt_content.stdWrap.dataProcessing {
    100 = Maispace\MaiAssets\DataProcessing\CriticalAssetDataProcessor
}
```

---

## Step 10 — Hooks

### `Hook/AboveFoldObserverHook.php`

Hook: `contentPostProc-output`

Logic:
1. Check if page is a fresh render — only inject if `$tsfe->no_cache === false` AND the page is cacheable
2. Read reset timestamp from `AboveFoldCacheService`
3. Build observer script with `PAGE_UID` and `SERVER_RESET_TIMESTAMP` injected
4. Dispatch `BeforeObserverScriptInjectedEvent` — allows full script replacement or cancellation
5. Inject script before `</body>`

The JS observer script (`Resources/Public/JavaScript/AboveFoldObserver.js`) must:
- Read viewport bucket and build localStorage key including `SERVER_RESET_TIMESTAMP`
- Skip if `localStorage` key timestamp >= `SERVER_RESET_TIMESTAMP`
- Observe all `[data-ce-uid]` elements
- After `window load` event: disconnect observer, POST to `/api/mai-assets/above-fold-report`
- On success: store `SERVER_RESET_TIMESTAMP` in localStorage
- Use `requestIdleCallback` if available to avoid blocking main thread

### `Hook/SvgSpriteInjectionHook.php`

Hook: `contentPostProc-output`

1. Call `SvgSpriteCollector::build()`
2. If empty, return early
3. Inject immediately after `<body[^>]*>` via `preg_replace`
4. Also inject font preload links into `<head>` via `FontPreloadCollector`

### `Hook/ContentElementSaveHook.php`

Hook: `processDatamap_afterDatabaseOperations` on `DataHandler`

1. Ignore all tables except `tt_content`
2. Resolve real UID for new records via `$dataHandler->substNEWwithIDs`
3. Check if changed fields intersect with `['sorting', 'colPos', 'pid', 'hidden', 'deleted', 'starttime', 'endtime']`
4. If no position-relevant change, return early
5. Resolve `$pageUid` via database lookup on `tt_content.pid`
6. Load all critical UIDs for page from `AboveFoldCacheService::getAllCriticalUids()`
7. Check if moved element UID is in any bucket's critical list
8. Check if moved element's new sorting is <= any critical element's sorting in same colPos
9. If either condition: `AboveFoldCacheService::clearCriticalUids()` + `bumpResetTimestamp()` + flush page cache tag

---

## Step 11 — Middleware

### `Middleware/AboveFoldReportMiddleware.php`

Implements `MiddlewareInterface`. Register before `typo3/cms-frontend/tsfe` in `Configuration/RequestMiddlewares.php`.

Route: `POST /api/mai-assets/above-fold-report`

Request body (JSON):

```json
{
    "pageUid": 5,
    "url": "https://example.com/page",
    "bucket": "desktop",
    "criticalUids": [12, 34, 56]
}
```

Validation:
- Method must be POST
- Path must match exactly
- `pageUid` must be positive integer
- `criticalUids` must be array of integers
- `bucket` must be one of configured buckets

On valid request:
1. Call `AboveFoldCacheService::updateCriticalUids()`
2. If changed: flush TYPO3 page cache tag `pageId_{pageUid}`
3. Return JSON `{"status": "ok", "changed": true|false}`
4. On invalid: return 400 with `{"status": "invalid", "errors": [...]}`

Pass all other requests to `$handler->handle($request)`.

---

## Step 12 — ViewHelpers

All ViewHelpers live under `Maispace\MaiAssets\ViewHelpers`. Register namespace in TypoScript:

```typoscript
config.namespaces.mai = Maispace\MaiAssets\ViewHelpers
```

### `ViewHelpers/Svg/IconViewHelper.php`

Arguments:
- `identifier` (string, required) — symbol ID
- `source` (string, required) — `EXT:` path to SVG file
- `label` (string, optional) — aria-label for meaningful icons
- `class` (string, optional)
- `size` (string, default `'1em'`)

Behaviour:
- Resolve source path via `FileResolutionTrait`
- Register with `SvgSpriteCollector`
- Render `<svg aria-hidden="true"><use href="#identifier"/></svg>` or with role/label if `label` provided

### `ViewHelpers/Asset/CriticalStyleViewHelper.php`

Arguments:
- `identifier` (string, required)
- `source` (string, optional) — `EXT:` path to CSS or SCSS file
- `isCritical` (bool, required)
- `media` (string, default `'all'`)

Behaviour:
- If source is `.scss`: run through `ScssProcessor`
- If `isCritical` true AND minification enabled: run through `MinificationProcessor`, then output `<style>` inline via `f:asset.style` with content
- If not critical: output `<link>` with `media="print"` and `onload="this.media='all'"` for deferred load

### `ViewHelpers/Asset/PreloadFontViewHelper.php`

Arguments:
- `path` (string, required) — `EXT:` path to woff2 file
- `isCritical` (bool, required)

Behaviour:
- If `isCritical`: register with `FontPreloadCollector`
- Always outputs nothing directly — injection happens in hook

### `ViewHelpers/Image/ResponsiveImageViewHelper.php`

Arguments:
- `image` (FileReference, required)
- `breakpoints` (array, required) — e.g. `{mobile: 400, tablet: 800, desktop: 1200}`
- `sizes` (string, required) — HTML `sizes` attribute value
- `isCritical` (bool, default false)
- `alt` (string, default '')
- `class` (string, optional)

Behaviour:
- Use `ImageVariantService` to process all variants
- Build `<picture>` with `<source>` for avif, webp, then `<img>` with jpeg srcset as fallback
- Set `loading="eager|lazy"`, `fetchpriority="high|low"`, `decoding="sync|async"` from `isCritical`
- If `isCritical`: register AVIF preload via TYPO3 `AssetCollector`

### `ViewHelpers/Video/VideoViewHelper.php`

Arguments:
- `file` (FileReference, optional) — self-hosted video
- `youtubeId` (string, optional)
- `vimeoId` (string, optional)
- `poster` (FileReference, optional)
- `isCritical` (bool, default false)
- `type` (string, default `'content'`) — `'background'` or `'content'`
- `title` (string, optional) — for facade accessibility
- `class` (string, optional)

Behaviour:
- `type='background'` + `isCritical`: `preload="metadata"`, `autoplay muted loop playsinline`
- `type='background'` + not critical: `preload="none"`, add `data-lazy` attribute, IntersectionObserver handles it
- `type='content'` with YouTube/Vimeo: render facade with poster image + play button SVG icon (from sprite), iframe injected on click
- `type='content'` self-hosted: `<video>` with `preload="none"` + `data-lazy`
- Always render AV1 → HEVC → H264 source order for self-hosted

---

## Step 13 — Events

All events are in `Classes/Event/`. All are final classes. All carry read-only constructor properties. Use TYPO3's PSR-14 event dispatcher.

### `AfterSpriteBuiltEvent`

```php
public function __construct(
    private string $sprite,
) {}
public function getSprite(): string {}
public function setSprite(string $sprite): void {}
```

### `AfterCriticalUidsUpdatedEvent`

```php
public function __construct(
    private readonly int $pageUid,
    private readonly string $bucket,
    private readonly array $previousUids,
    private readonly array $newUids,
) {}
```

### `BeforeAssetInjectionEvent`

```php
public function __construct(
    private string $content,
    private readonly string $type,   // 'css' | 'js'
    private readonly string $source,
) {}
public function getContent(): string {}
public function setContent(string $content): void {}
```

### `BeforeObserverScriptInjectedEvent`

```php
public function __construct(
    private string $script,
    private bool $cancelled = false,
) {}
public function cancel(): void {}
public function isCancelled(): bool {}
public function setScript(string $script): void {}
```

### `ModifyCriticalThresholdEvent`

```php
public function __construct(
    private int $threshold,
    private readonly int $colPos,
    private readonly int $pageUid,
) {}
public function getThreshold(): int {}
public function setThreshold(int $threshold): void {}
```

Register all events in `Configuration/Services.yaml` as event listeners with proper tags.

---

## Step 14 — TCA Extension

### `Configuration/TCA/Overrides/tt_content.php`

Add field `tx_maiassets_is_critical`:

```php
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('tt_content', [
    'tx_maiassets_is_critical' => [
        'label'  => 'LLL:EXT:mai_assets/Resources/Private/Language/locallang_db.xlf:tx_maiassets_is_critical',
        'config' => ['type' => 'check', 'default' => 0],
        'displayCond' => 'FIELD:tx_maiassets_force_critical:REQ:false',
    ],
    'tx_maiassets_force_critical' => [
        'label'  => 'LLL:EXT:mai_assets/Resources/Private/Language/locallang_db.xlf:tx_maiassets_force_critical',
        'description' => 'LLL:EXT:mai_assets/Resources/Private/Language/locallang_db.xlf:tx_maiassets_force_critical.description',
        'config' => ['type' => 'check', 'default' => 0],
    ],
]);
```

`tx_maiassets_force_critical` bypasses the cache service entirely and always inlines — for editors who know best. `tx_maiassets_is_critical` is the editor override that overrides automatic detection.

The `CriticalDetectionService::isCritical()` must check in this order:
1. `tx_maiassets_force_critical` → always true
2. `tx_maiassets_is_critical` → honour editor override
3. Cache service result (from observer reports)
4. Fallback: position/sorting heuristic from `ExtensionConfiguration`

---

## Step 15 — SCSS Support Details

The `ScssProcessor` must:
- Accept absolute file path as source
- Add the source file's directory to SCSS import paths
- Accept additional import paths from `ExtensionConfiguration` (e.g. a global variables file)
- Cache compiled output keyed by `md5(file_get_contents($path) . $settingsHash)`
- Store compiled CSS in `typo3temp/assets/mai_assets/compiled/`
- On cache hit, return cached file content
- Thread compile errors through to a TYPO3 flash message in backend context, or throw `RuntimeException` in frontend context

---

## Step 16 — Compression Details

After any processed asset is written to `typo3temp/assets/mai_assets/`:

```
file.css      → always written
file.css.gz   → written if enableCompression = true
file.css.br   → written if enableBrotli = true AND ext-brotli loaded
```

Document in RST the required web server configuration:

**Nginx:**
```nginx
gzip_static on;
brotli_static on;
```

**Apache:**
```apache
<IfModule mod_rewrite.c>
  RewriteCond %{HTTP:Accept-Encoding} br
  RewriteCond %{REQUEST_FILENAME}.br -f
  RewriteRule ^(.*)$ $1.br [L]
</IfModule>
```

---

## Step 17 — TypoScript & Constants

### `Configuration/TypoScript/constants.typoscript`

```typoscript
plugin.tx_maiassets {
    settings {
        enableScssProcessing = 1
        enableMinification = 1
        enableCompression = 1
        enableBrotli = 1
        observerRootMargin = 200px
    }
}
```

### `Configuration/TypoScript/setup.typoscript`

- Include the static file in `ext_localconf.php`
- Register DataProcessor on all `tt_content` rendering
- Register namespace alias for ViewHelpers

---

## Step 18 — Documentation (RST)

All documentation lives in `Documentation/`. Follow TYPO3 documentation standards. Every file must have proper RST headings, cross-references, and code blocks with syntax highlighting.

### `Documentation/Index.rst`

Top-level entry point. Title, brief description, toctree linking all sub-pages.

### `Documentation/Introduction/Index.rst`

- What the extension does
- Architecture overview diagram (ASCII art acceptable)
- The critical/deferred concept explained
- The self-optimising loop explained

### `Documentation/Installation/Index.rst`

- Composer install command
- Activate extension
- Include TypoScript static file
- Run database compare
- Web server configuration for pre-compressed files

### `Documentation/Configuration/Index.rst`

- Full table of all Extension Manager settings with type, default, and description
- TypoScript constants reference
- `CriticalThresholdByColPos` example
- `ViewportBuckets` example

### `Documentation/ViewHelpers/Index.rst`

One section per ViewHelper. For each:
- Full argument table (name, type, required, default, description)
- Minimal usage example
- Full usage example with all arguments
- Notes on interaction with critical system

### `Documentation/Events/Index.rst`

One section per event. For each:
- Purpose
- Available methods
- Example listener class (full PHP)
- Registration in `Services.yaml`

### `Documentation/Developer/Index.rst`

- How to implement `AssetProcessorInterface` for custom processors
- How to implement `AssetCollectorInterface` for custom collectors
- How to extend the critical detection via `ModifyCriticalThresholdEvent`
- How to add custom viewport buckets
- The observer JS flow explained
- Cache architecture diagram
- How to disable the observer for specific pages via TypoScript condition

### `Documentation/Changelog/Index.rst`

Initial entry for version 1.0.0 listing all features.

---

## Step 19 — Services.yaml

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    Maispace\MaiAssets\:
        resource: '../Classes/*'
        exclude:
            - '../Classes/Event/*'

    # Collectors must be singletons
    Maispace\MaiAssets\Collector\SvgSpriteCollector:
        shared: true

    Maispace\MaiAssets\Collector\FontPreloadCollector:
        shared: true

    Maispace\MaiAssets\Cache\AboveFoldCacheService:
        shared: true
```

---

## Step 20 — Testing Checklist

Before marking the extension complete, verify:

- [ ] Fresh page render injects observer JS
- [ ] Cached page does NOT inject observer JS
- [ ] POST to `/api/mai-assets/above-fold-report` stores UIDs correctly
- [ ] No change → no cache flush
- [ ] Change detected → page cache flushed
- [ ] Second render uses critical UIDs correctly
- [ ] Moving a CE in backend triggers cache invalidation
- [ ] Observer re-runs after editor moves CE (reset timestamp mechanism)
- [ ] SVG sprite contains only icons used on current page
- [ ] SCSS files compile and cache correctly
- [ ] Minified output is smaller than input
- [ ] `.gz` and `.br` files written alongside processed assets
- [ ] `isCritical=true` images get `fetchpriority="high"` and `loading="eager"`
- [ ] `isCritical=false` images get `loading="lazy"`
- [ ] Critical fonts get `<link rel="preload">` in `<head>`
- [ ] YouTube facade does not load iframe until click
- [ ] Background video with `isCritical=false` gets `data-lazy` and `preload="none"`
- [ ] All RST documentation renders without errors
- [ ] All events fire at the correct points and can be overridden
- [ ] `tx_maiassets_force_critical` always results in inlined styles regardless of cache state

---

## Coding Standards

- Strict types declared in every file: `declare(strict_types=1);`
- All classes final unless explicitly designed for extension
- No static calls except in `ext_localconf.php` registration and `GeneralUtility::makeInstance()` legacy compatibility
- Prefer constructor injection everywhere
- All public methods must have return types
- All properties must be typed
- PHPDoc only where type system cannot express the type
- No `@suppress` annotations — fix the issue instead
- Follow PSR-12 formatting
- Use TYPO3 `LogManager` for any logging — never `error_log()`

---

## Delivery Order

Build in this sequence to avoid dependency issues:

1. `composer.json` + `ext_emconf.php` + `ext_localconf.php` skeleton
2. `ExtensionConfiguration`
3. Traits
4. Interfaces + Abstracts
5. `AboveFoldCacheService`
6. Asset Processors (SCSS → Minify → Compress)
7. Collectors (SVG, Font)
8. Services
9. Events
10. DataProcessor
11. Hooks
12. Middleware
13. ViewHelpers
14. TCA extensions + language files
15. TypoScript files
16. Observer JS
17. `Services.yaml`
18. Documentation (RST)
19. Run full testing checklist
