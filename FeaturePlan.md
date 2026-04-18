# FeaturePlan.md — typo3-extension-assets

Authoritative feature plan for the `mai_assets` extension.
Supersedes `MISSING_FEATURES.md` (restoration-oriented) with a forward-looking, design-conscious approach.

---

## 1. Current State Inventory

What already exists after the `630f7c2` refactor — do not re-implement:

| Layer | What exists |
|---|---|
| **Processing** | `AbstractAssetProcessor` → `MinificationProcessor` (CSS+JS), `ScssProcessor`, `CompressionProcessor`; file-system cache in `typo3temp/assets/mai_assets/compiled/` |
| **Collectors** | `AbstractAssetCollector` → `SvgSpriteCollector` (builds sprite SVG, fires `AfterSpriteBuiltEvent`), `FontPreloadCollector` (builds preload links) |
| **Services** | `CriticalDetectionService` (colPos-based above-fold scoring), `FontPreloadService`, `ImageVariantService` (srcset variants) |
| **ViewHelpers** | `CriticalStyleViewHelper` (inline/deferred CSS+SCSS), `ResponsiveImageViewHelper` (srcset), `PreloadFontViewHelper`, `IconViewHelper` (SVG sprite `<use>`), `VideoViewHelper` |
| **Events** | `AfterSpriteBuiltEvent`, `BeforeAssetInjectionEvent`, `BeforeObserverScriptInjectedEvent`, `AfterCriticalUidsUpdatedEvent`, `ModifyCriticalThresholdEvent` |
| **Above-fold pipeline** | JS `IntersectionObserver` → `AboveFoldReportMiddleware` → `AboveFoldCacheService`; `CriticalAssetDataProcessor`; `ContentElementSaveHook` (cache invalidation) |
| **Traits** | `FileResolutionTrait`, `CacheKeyTrait`, `CriticalAwareTrait` |
| **Config** | `ExtensionConfiguration` (typed wrapper around `ext_conf_template.txt`); QA scaffolding (`phpstan.neon`, `phpunit.xml.dist`, `typoscript-lint.yml`) |

---

## 2. Drop Decisions

The following items from `MISSING_FEATURES.md` are **deliberately dropped** — not because they aren't valuable but because a better alternative already exists or the effort/value ratio is wrong.

### 2.1 `ChromiumCdpClient` + `CriticalCssExtractCommand` (Chromium-based) — **DROP**

The old approach crawled pages with headless Chromium to extract critical CSS. The current
`AboveFoldReportMiddleware` + JS `IntersectionObserver` approach is strictly superior:
- Zero external infrastructure (no Chromium binary to manage)
- Runtime-adaptive (responds to actual user viewports, not a static headless screenshot)
- Works per-language, per-page, per-device-bucket automatically
- Scales without CI/CD hooks

**Decision**: the Chromium pipeline is dead. Do not restore it.

### 2.2 Monolithic `AssetProcessingService` (~963 lines) — **DROP**

The current processor pattern (`AbstractAssetProcessor` + specialised subclasses, each
responsible for one transformation) is architecturally better than a single 963-line god
service. The old service's responsibilities are already distributed across `ScssProcessor`,
`MinificationProcessor`, `CompressionProcessor` and the ViewHelper layer.

**Decision**: do not restore the monolith. Extend the processor hierarchy instead.

### 2.3 Separate `<mai:scss>` ViewHelper — **DROP / MERGE**

A standalone `<mai:scss>` ViewHelper duplicates most of what `<mai:css>` will do once it
supports `.scss` extension auto-detection (see §3.1). There is no user-facing benefit to
two separate ViewHelpers when the difference is just the file extension.

**Decision**: `<mai:css>` auto-detects `.scss` and compiles transparently. No `<mai:scss>`.

### 2.4 `<mai:lottie>` — **DEFER**

Lottie animations are a niche requirement. CSS animations and the Web Animations API
cover the majority of use cases in 2026. No current content type or template in this
project needs Lottie.

**Decision**: implement only if a concrete use case arises. Not in scope for this plan.

### 2.5 RST Documentation — **DROP**

RST documentation (`.rst` files) requires a Sphinx pipeline. Markdown is sufficient for
this project. Document everything in `.md` files alongside the code.

---

## 3. Feature Plan

---

### 3.1 `<mai:css>` — General CSS/SCSS inclusion ViewHelper (NEW)

**File**: `Classes/ViewHelpers/Asset/CssViewHelper.php`

**Purpose**: Registers any CSS or SCSS file as a page asset. SCSS is auto-detected from
extension and compiled via `ScssProcessor`. Replaces the narrow `CriticalStyleViewHelper`
for the non-critical path (that ViewHelper stays for the above-fold inline use case).

**Delivery strategy**: `AssetCollector::addStyleSheet()` for file-based registration.
Inline mode (`inline=true`) reads the file, compiles+minifies, and emits a `<style>` block.

**Arguments**:

| Argument | Type | Default | Purpose |
|---|---|---|---|
| `identifier` | string | *required* | Deduplication key |
| `src` | string | *required* | EXT: path or absolute path |
| `priority` | bool | `false` | Render in `<head>` before page CSS |
| `minify` | bool | from config | Override per-call minification |
| `inline` | bool | `false` | Embed as `<style>` block |
| `media` | string | `'all'` | CSS media attribute |
| `nonce` | string | `''` | CSP nonce for inline blocks |
| `integrity` | string | `''` | Explicit SRI hash; auto-computed if empty and file is local |
| `crossorigin` | string | `''` | CORS attribute |

**Design decisions**:
- SCSS detection: `pathinfo($src, PATHINFO_EXTENSION) === 'scss'` → delegate to `ScssProcessor`
- SRI: computed by `SriHashService` (see §3.9); omitted for external URLs unless `integrity` is explicitly set
- `inline=true`: compile + minify + emit `<style>` with optional `nonce`; uses `BeforeAssetInjectionEvent`
- File-based: `AssetCollector::addStyleSheet($identifier, $publicPath, $tagAttributes)` where `$tagAttributes` carries `integrity`, `crossorigin`
- Does **not** handle above-fold inlining — that is `CriticalStyleViewHelper`'s job

**Skeleton**:

```php
final class CssViewHelper extends AbstractViewHelper
{
    use FileResolutionTrait;

    protected $escapeOutput = false;

    public function __construct(
        private readonly ScssProcessor $scssProcessor,
        private readonly MinificationProcessor $minificationProcessor,
        private readonly SriHashService $sriHashService,
        private readonly AssetCollector $assetCollector,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) { parent::__construct(); }

    // initializeArguments() registers the table above

    public function render(): string { ... }
}
```

---

### 3.2 `<mai:js>` — JavaScript inclusion ViewHelper (NEW)

**File**: `Classes/ViewHelpers/Asset/JsViewHelper.php`

**Purpose**: Registers a JS file as a page asset via `AssetCollector::addJavaScript()`.
Minification via `MinificationProcessor`. Module support via `type="module"`.

**Arguments**:

| Argument | Type | Default | Purpose |
|---|---|---|---|
| `identifier` | string | *required* | Deduplication key |
| `src` | string | *required* | EXT: path or absolute path |
| `priority` | bool | `false` | `<head>` (true) vs. footer (false) |
| `minify` | bool | from config | Override per-call |
| `defer` | bool | `true` | `defer` attribute |
| `async` | bool | `false` | `async` attribute (mutually exclusive with `defer`) |
| `type` | string | `''` | MIME type; set `'module'` for ES6 modules |
| `nomodule` | bool | `false` | Fallback for non-module browsers |
| `nonce` | string | `''` | CSP nonce |
| `integrity` | string | `''` | SRI hash; auto-computed for local files |
| `crossorigin` | string | `''` | CORS attribute |

**Design decisions**:
- `defer` defaults to `true` (modern best practice for all non-module scripts)
- `type="module"` scripts are always deferred by the browser spec; set `defer=false` and `type=module` together is a no-op
- SRI auto-computation via `SriHashService` (see §3.9)
- `AssetCollector::addJavaScript($identifier, $publicPath, $attributes, $options)` where `$options['priority'] = $priority`
- Fires `BeforeAssetInjectionEvent` before registration

---

### 3.3 `<mai:picture>` + `<mai:picture.source>` — Art-directed responsive picture (NEW)

**Files**:
- `Classes/ViewHelpers/Image/PictureViewHelper.php`
- `Classes/ViewHelpers/Image/Picture/SourceViewHelper.php`

**Purpose**: Generates a `<picture>` element for art-directed responsive images. Child
`<mai:picture.source>` tags define breakpoint-specific `<source>` elements. A fallback
`<img>` (with srcset, if supported) renders inside `<picture>`.

**`<mai:picture>` arguments**: `image`, `alt`, `width`, `height`, `lazyloading`, `fetchPriority`, `quality`, `fileExtension` (fallback format), `crossorigin`, `class`

**`<mai:picture.source>` arguments**: `media` (required), `srcset` (widths array), `sizes`, `formats` (e.g. `['avif', 'webp']`), `quality`, `width`, `height`

**Design decisions**:
- `PictureViewHelper` is a "container" ViewHelper (`$escapeOutput = false`). It renders its
  child content (source tags) and wraps them with `<picture>…<img …></picture>`
- Sources communicate to the parent via a shared `RenderingContextVariable` (standard
  Fluid `ChildNodeAccessor` pattern); no static state
- `ImageVariantService` is reused for generating `ProcessedFile` variants per source
- `<mai:picture.source>` renders nothing on its own outside a `<picture>` context

---

### 3.4 `<mai:figure>` — Semantic figure wrapper (NEW)

**File**: `Classes/ViewHelpers/Image/FigureViewHelper.php`

**Purpose**: Wraps child content (typically `<mai:image>` or `<mai:picture>`) in a
`<figure>` with optional `<figcaption>`. Purely structural, zero dependencies.

**Arguments**: `caption`, `class`, `id`, `role`

**Design**: Renders `<figure {attrs}>{children}<figcaption>{caption}</figcaption></figure>`.
Omits `<figcaption>` when `caption` is empty.

---

### 3.5 `<mai:svgInline>` — Inline SVG embedding (NEW)

**File**: `Classes/ViewHelpers/Svg/InlineViewHelper.php`

**Purpose**: Reads an SVG file, strips the XML declaration, optionally strips `width`/`height`
attributes, and outputs the raw SVG markup inline.

**Arguments**: `src` (EXT: path), `title` (injects `<title>`), `ariaLabel`, `class`, `stripDimensions` (bool, default `true`)

**Design decisions**:
- Cache result in TYPO3 caching framework via `CacheKeyTrait`; key = sha256(file) + arguments hash
- Uses `FileResolutionTrait::requireFile()` for path resolution
- Inject `<title>` element as first child of `<svg>` when provided (accessibility)
- Strip `width`/`height` from `<svg>` root when `stripDimensions=true` (CSS sizing preferred)
- Add `aria-label` and `role="img"` when `ariaLabel` is provided

---

### 3.6 `<mai:hint>` — Resource hints ViewHelper (NEW)

**File**: `Classes/ViewHelpers/Asset/HintViewHelper.php`

**Purpose**: Emits a `<link rel="…">` resource hint into `<head>` via `PageRenderer::addHeaderData()`.

**Arguments**:

| Argument | Type | Allowed values |
|---|---|---|
| `rel` | string | `preconnect`, `dns-prefetch`, `preload`, `prefetch`, `modulepreload` |
| `href` | string | URL |
| `as` | string | `script`, `style`, `font`, `image`, `fetch` (for `preload`/`modulepreload`) |
| `type` | string | MIME type (optional, for fonts: `font/woff2`) |
| `crossorigin` | string | `''` or `'anonymous'` or `'use-credentials'` |
| `media` | string | Media query (for preload of print CSS etc.) |

**Design decisions**:
- `preconnect` should always set `crossorigin="anonymous"` (CORS-aware preconnect); warn if not set
- `preload` with `as="font"` requires `crossorigin` — enforce or auto-add
- Uses `PageRenderer::addHeaderData()` with a deduplication key based on rel+href
- Does not use `AssetCollector` — there is no equivalent API for resource hints

---

### 3.7 `CriticalStyleViewHelper` — Extend existing

**Current gap**: The ViewHelper resolves SCSS but writes the public path back using a
home-grown `getPublicPath()` that relies on `PATH_site`. This should use the standard
`Environment::getPublicPath()` + `PathUtility::getAbsoluteWebPath()` to be safe.

**Also**: The deferred (non-critical) path emits raw `<link>` HTML directly into the
template output. This is correct but bypasses deduplication. Consider registering via
`AssetCollector` instead and returning `''` from the ViewHelper — the asset will be injected
by the collector at the correct render position.

**Changes**:
1. Replace `getPublicPath()` with `PathUtility::getAbsoluteWebPath($absolutePath)`
2. For `isCritical=false`: switch to `AssetCollector::addStyleSheet()` instead of emitting raw HTML

---

### 3.8 `SvgSpriteCollector` + `FontPreloadCollector` — Add auto-discovery (EXTEND)

**Current gap**: Both collectors have a `register(string $identifier, string $filePath): void`
API but nothing discovers symbols/fonts from `Configuration/SpriteIcons.php` or
`Configuration/Fonts.php` files shipped by other extensions.

**Add `ExtensionConfigurationDiscovery` service** (NEW):

**File**: `Classes/Configuration/ExtensionConfigurationDiscovery.php`

```php
final class ExtensionConfigurationDiscovery
{
    public function discoverSpriteIcons(): array   // returns [siteScope => [identifier => svgPath]]
    public function discoverFonts(): array         // returns [siteScope => [[src, type]]]
}
```

**How it works**:
1. Uses `ExtensionManagementUtility::getLoadedExtensionListArray()` to get all active extensions
2. For each extension, checks for `Configuration/SpriteIcons.php` / `Configuration/Fonts.php`
3. Validates the returned array structure
4. Filters by `sites` key against current site identifier (from `SiteFinder`)

**Integration**:
- `SvgSpriteCollector::build()` calls `ExtensionConfigurationDiscovery::discoverSpriteIcons()` and auto-registers on first build
- `FontPreloadCollector::build()` calls `discoverFonts()` similarly

**`Configuration/SpriteIcons.php` contract** (unchanged from `MISSING_FEATURES.md`):
```php
return [
    'sites' => ['*'],
    'icons' => [
        'arrow-right' => 'EXT:my_ext/Resources/Public/Icons/arrow-right.svg',
    ],
];
```

**`Configuration/Fonts.php` contract** (unchanged):
```php
return [
    'sites' => ['*'],
    'fonts' => [
        ['src' => 'EXT:my_ext/Resources/Public/Fonts/Inter.woff2', 'type' => 'font/woff2'],
    ],
];
```

---

### 3.9 `SriHashService` (NEW)

**File**: `Classes/Service/SriHashService.php`

**Purpose**: Computes `sha384-{base64}` Subresource Integrity hashes for local asset files.
Used by `CssViewHelper` and `JsViewHelper`.

```php
final class SriHashService
{
    public function computeForFile(string $absolutePath): string  // returns "sha384-{base64}"
    public function computeForContent(string $content): string
}
```

**Design decisions**:
- Algorithm: SHA-384 (mandatory minimum per W3C SRI spec)
- Cache: result stored by `CacheKeyTrait` key = sha256(file) → no recomputation on every request
- External URLs: caller is responsible for not calling this; service throws `AssetFileNotFoundException` if path does not exist
- Returns prefixed string `"sha384-{base64}"` ready for use as `integrity` attribute value

---

### 3.10 Exception Hierarchy (NEW)

**Directory**: `Classes/Exception/`

| Class | Extends | Thrown when |
|---|---|---|
| `AssetException` | `\RuntimeException` | Base class — catch-all for extension errors |
| `AssetFileNotFoundException` | `AssetException` | Referenced file does not exist on filesystem |
| `AssetWriteException` | `AssetException` | Cannot write compiled/minified output to cache directory |
| `AssetCompilationException` | `AssetException` | SCSS compilation failure (wraps scssphp exception) |
| `InvalidAssetConfigurationException` | `AssetException` | Bad TypoScript or ViewHelper configuration |
| `InvalidImageInputException` | `AssetException` | Unsupported or missing image source |

**Integration**: Replace generic `\RuntimeException` and bare `throw new \Exception` usages
across processors, services, and ViewHelpers with the appropriate typed exception.
`FileResolutionTrait::requireFile()` should throw `AssetFileNotFoundException`.
`ScssProcessor::doProcess()` should wrap `CompilerException` into `AssetCompilationException`.

---

### 3.11 Additional PSR-14 Events (EXTEND)

The existing event set covers sprites, above-fold and asset injection. Missing events for
the processing pipeline:

| New event | Fire point | Key methods |
|---|---|---|
| `AfterCssProcessedEvent` | After `MinificationProcessor` finishes CSS | `getProcessedCss()`, `setProcessedCss()`, `getSourcePath()` |
| `AfterJsProcessedEvent` | After `MinificationProcessor` finishes JS | `getProcessedJs()`, `setProcessedJs()`, `getSourcePath()` |
| `AfterScssCompiledEvent` | After `ScssProcessor::doProcess()` | `getCompiledCss()`, `setCompiledCss()`, `getSourcePath()` |
| `BeforeSpriteSymbolRegisteredEvent` | Before each icon is added to `SvgSpriteCollector` | `getIdentifier()`, `getSvgPath()`, `cancel()` |
| `BeforeImageProcessingEvent` | Before `ImageVariantService::processVariants()` | `getFileReference()`, `getBreakpoints()`, modify or cancel |
| `AfterImageProcessedEvent` | After `ImageVariantService::processVariants()` | `getVariants()` (array of `ProcessedFile`) |

**Integration**:
- `AfterCssProcessedEvent` / `AfterJsProcessedEvent`: fire from `AbstractAssetProcessor::process()` after `doProcess()`, replacing the current undifferentiated `BeforeAssetInjectionEvent` (which fires post-processing — rename that event or keep both)
- `AfterScssCompiledEvent`: fire from `ScssProcessor::doProcess()` before caching
- `BeforeSpriteSymbolRegisteredEvent`: fire from `SvgSpriteCollector::register()` (add event dispatch there)
- Image events: fire from `ImageVariantService`

---

### 3.12 `WarmupCommand` (NEW)

**File**: `Classes/Command/WarmupCommand.php`

**Command**: `vendor/bin/typo3 maispace:assets:warmup`

**Purpose**: Pre-warms asset caches at deploy time. Triggers compilation + minification
for all assets discovered via `Configuration/SpriteIcons.php` and `Configuration/Fonts.php`
across loaded extensions, so the first frontend request is served from cache.

**Approach**:
1. Discover all sprite icon SVG paths via `ExtensionConfigurationDiscovery::discoverSpriteIcons()`
2. For each: call `SvgSpriteCollector::build()` to prime the SVG sprite cache
3. Discover CSS/SCSS paths registered via TypoScript (`plugin.tx_maispace_assets.settings.warmup.assets`) — a new optional array setting
4. For each: run through `ScssProcessor` (if `.scss`) then `MinificationProcessor`
5. Report: files processed, cache hits, errors

**Note**: Does not replace the above-fold observer pipeline. Above-fold data is
runtime-collected per page per device-bucket; it cannot be warmed up at deploy time.

---

### 3.13 TypoScript Configuration (EXTEND)

The current `ExtensionConfiguration` wraps `ext_conf_template.txt` settings. The TypoScript
`plugin.tx_maispace_assets.settings` tree needs to be documented and expanded for new features.

Add to `Configuration/TypoScript/setup.typoscript`:

```typoscript
plugin.tx_maispace_assets {
    settings {
        # Asset processing
        css {
            minify           = 1
            outputDir        = typo3temp/assets/mai_assets/compiled/
        }
        js {
            minify           = 1
            defer            = 1
        }
        scss {
            minify           = 1
            defaultImportPaths =
        }

        # Warmup: absolute EXT: paths to pre-warm
        warmup {
            assets {
                10 = EXT:mai_theme/Resources/Public/Css/main.scss
            }
        }

        # SVG sprite
        svgSprite {
            symbolIdPrefix   = icon-
        }

        # Fonts
        fonts {
            preloadFormats   = woff2
        }

        # Image variants
        image {
            lazyloading      = 1
            lazyloadClass    = lazyload
            forceFormat      =
            alternativeFormats = avif,webp
        }

        # Compression (gzip/brotli of compiled files)
        compression {
            enable = 1
            brotli = 1
            gzip   = 1
        }

        # HTML output minification
        htmlMinification {
            enable           = 0
            stripComments    = 1
            preserveTags     = pre,code,textarea
        }
    }
}
```

### 3.14 HTML Minification (NEW)

**Files**:
- `Classes/Service/HtmlMinificationService.php` — pure transformation service, no DI, no side effects
- `Classes/EventListener/HtmlMinificationListener.php` — listens to `AfterCacheableContentIsGeneratedEvent`, reads TypoScript config, delegates

**Purpose**: Strip inter-element whitespace and HTML comments from the final cached HTML
response, reducing transfer size without altering rendered output. Runs once per page per
cache lifetime (result is cached with the page).

#### Event wiring

```php
#[AsEventListener(
    identifier: 'mai-assets/html-minification',
    after: 'mai-assets/svg-sprite-injection',
)]
```

Runs **after** `SvgSpriteInjectionListener` and `AboveFoldObserverListener` so that
injected sprite and observer markup is also minified.

#### Content-type guard

The listener checks `$event->getResponse()->getHeaderLine('Content-Type')`. If the response
is not `text/html`, minification is skipped entirely (protects JSON/XML API sub-requests).

#### TypoScript configuration

Add to `plugin.tx_maispace_assets.settings` (also document in §3.13):

```typoscript
htmlMinification {
    enable           = 0
    stripComments    = 1
    preserveTags     = pre,code,textarea
}
```

`enable = 0` by default — must be explicitly opted in.
`preserveTags` is a comma-separated list of tags whose entire content (including whitespace)
is protected from any transformation. `<script>`, `<style>`, and JSON/LD blocks are always
protected regardless of this setting.

#### `HtmlMinificationService` public API

```php
final class HtmlMinificationService
{
    public function minify(string $html, array $config): string;
    private function protectBlocks(string $html, array $preserveTags): array; // [protected_html, map]
    private function restoreBlocks(string $html, array $map): string;
    private function stripComments(string $html): string;
    private function collapseWhitespace(string $html): string;
}
```

#### Block protection strategy

Before any transformation, `protectBlocks()` replaces protected content with unique
placeholder tokens (`\x00PROTECTED_{n}\x00`). After all transformations, `restoreBlocks()`
substitutes the tokens back. Order: protect → strip comments → collapse whitespace → restore.

**Always-protected** (regardless of `preserveTags` setting):
- `<script>` / `</script>` (all script blocks, including `type="text/javascript"`)
- `<style>` / `</style>`
- `<script type="application/json">` and `<script type="application/ld+json">` blocks — JSON is never touched
- `<textarea>` (form value content)

**Configurable via `preserveTags`** (defaults: `pre,code,textarea`):
- `<pre>` — whitespace is significant
- `<code>` — inline code should not be reflow-mangled
- `<textarea>` — in addition to always-protected, keeps it in the configurable list so it can be removed if desired

#### Comment stripping

When `stripComments = 1`, HTML comments (`<!-- … -->`) are removed with the following
exceptions — these TYPO3-internal markers must be preserved:

| Pattern | Used for |
|---|---|
| `<!--INT_SCRIPT.*-->` | Non-cached page markers |
| `<!--HD_-->`, `<!--TDS_-->`, `<!--FD_-->` | Head/footer data markers |
| `<!--CSS_INCLUDE_-->`, `<!--CSS_INLINE_-->` | CSS injection markers |
| `<!--JS_LIBS-->`, `<!--JS_INCLUDE-->`, `<!--JS_INLINE-->` | JS injection markers |
| `<!--JS_LIBS_FOOTER-->`, `<!--JS_INCLUDE_FOOTER-->`, `<!--JS_INLINE_FOOTER-->` | Footer JS markers |
| `<!--HEADERDATA-->`, `<!--FOOTERDATA-->` | Header/footer data |
| `<!--TYPO3SEARCH_begin-->`, `<!--TYPO3SEARCH_end-->` | Indexed search markers |
| `<!-- ###…` | Section markers used by some content elements |

Implementation: a single `preg_replace` with a negative lookahead excludes matching patterns
before removing the rest.

#### Whitespace collapsing

1. Each line: trim leading and trailing whitespace
2. Runs of whitespace characters (space, tab, `\r`, `\n`) between `>` and `<` are collapsed
   to a single space
3. Lines that become empty after trimming are discarded

Collapsing is applied **only** to non-protected content (i.e., after block protection).

#### TypoScript access in listener

```php
$tsSettings = $event->getRequest()
    ->getAttribute('frontend.typoscript')
    ?->getSetupArray()['plugin.']['tx_maispace_assets.']['settings.']['htmlMinification.']
    ?? [];
```

---

## 4. Architecture Constraints

These rules must be followed in all new code:

1. **AssetCollector over PageRenderer** for registering CSS/JS files — `PageRenderer::addCssFile()`, `addJsFile()` etc. are deprecated in TYPO3 14. New ViewHelpers use `AssetCollector`.
2. **PageRenderer** is still used for raw `<head>` data (resource hints, inline styles with nonce).
3. **No static calls** to `GeneralUtility::makeInstance()` in new service classes — use constructor DI.
4. **Processors stay single-responsibility** — one processor, one transformation. Do not add SCSS compilation to `MinificationProcessor`.
5. **Traits are helpers, not feature implementations** — `FileResolutionTrait`, `CacheKeyTrait`, `CriticalAwareTrait` provide utilities. Business logic lives in services.
6. **All new public service methods must be covered by unit tests**.
7. **All new exceptions must be thrown with a descriptive message** containing the problematic path or value.

---

## 5. What Stays Untouched

- `CriticalDetectionService` — the above-fold scoring logic is correct and complete
- `AboveFoldCacheService` — solid implementation, no changes needed
- `AboveFoldReportMiddleware` — replaces the entire Chromium approach; keep as-is
- `CriticalAssetDataProcessor` — correct integration point for content element `isCritical` flag
- `VideoViewHelper` — separate concern, not related to asset pipeline gaps
- `ResponsiveImageViewHelper` — functional; only extend once `<mai:picture>` is built (for shared logic extraction into `ImageVariantService`)

---

## 6. Implementation Order

Ordered by dependency graph (each item can start once its prerequisites are ✓):

| # | Deliverable | Prerequisites | Effort |
|---|---|---|---|
| 1 | Exception hierarchy (`Classes/Exception/`) | none | XS |
| 2 | `SriHashService` | exception hierarchy | S |
| 3 | `ExtensionConfigurationDiscovery` | exception hierarchy | S |
| 4 | `SvgSpriteCollector` + `FontPreloadCollector` auto-discovery | `ExtensionConfigurationDiscovery` | S |
| 5 | New PSR-14 events (§3.11) | none | S |
| 6 | Fire new events from `AbstractAssetProcessor`, `ScssProcessor`, `SvgSpriteCollector`, `ImageVariantService` | step 5 | S |
| 7 | `<mai:css>` ViewHelper | `SriHashService`, exception hierarchy | M |
| 8 | `<mai:js>` ViewHelper | `SriHashService`, exception hierarchy | M |
| 9 | `CriticalStyleViewHelper` fixes (§3.7) | none | XS |
| 10 | `<mai:hint>` ViewHelper | none | S |
| 11 | `<mai:svgInline>` ViewHelper | exception hierarchy, `CacheKeyTrait` | S |
| 12 | `<mai:picture>` + `<mai:picture.source>` | `ImageVariantService` | L |
| 13 | `<mai:figure>` | none | XS |
| 14 | `WarmupCommand` | `ExtensionConfigurationDiscovery`, processors | M |
| 15 | `HtmlMinificationService` + `HtmlMinificationListener` (§3.14) | exception hierarchy | M |
| 16 | TypoScript settings expansion (§3.13, including `htmlMinification`) | all above | S |
| 17 | Unit tests for all new code | each step | ongoing |

**Effort key**: XS < 1h · S 1-2h · M 2-4h · L 4-8h
