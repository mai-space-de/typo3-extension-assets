# Missing Features — Restoration Plan

The commit `630f7c2` (Mar 29, 2026) removed ~18,500 lines across 92 files as part of a strategic
refactor. The sections below document every feature that existed in earlier versions and is no
longer present, grouped by area. Each entry notes the deleted file(s) so the git history can be
used as the source of truth during re-implementation.

---

## 1. Asset Inclusion ViewHelpers

These Fluid ViewHelpers allowed templates to register CSS, JS and SCSS assets directly, with full
control over processing, delivery and security attributes.

### 1.1 `<mai:css>` — CSS inclusion
**Deleted file:** `Classes/ViewHelpers/CssViewHelper.php`

Arguments that need to be supported:

| Argument | Purpose |
|---|---|
| `identifier` | Deduplification key |
| `src` | File path, EXT: path or external URL |
| `priority` | Emit in `<head>` vs. footer |
| `minify` | Enable/disable minification |
| `inline` | Embed as `<style>` instead of `<link>` |
| `deferred` | Load asynchronously via `media` swap trick |
| `media` | Target media query |
| `nonce` | CSP nonce for inline blocks |
| `integrity` / `integrityValue` | SRI hash (auto-computed or explicit) |
| `crossorigin` | CORS attribute |

### 1.2 `<mai:js>` — JavaScript inclusion
**Deleted file:** `Classes/ViewHelpers/JsViewHelper.php`

Arguments that need to be supported:

| Argument | Purpose |
|---|---|
| `identifier` | Deduplification key |
| `src` | File path, EXT: path or external URL |
| `priority` | `<head>` vs. footer |
| `minify` | Enable/disable minification |
| `defer` / `async` | Script loading strategy |
| `type` | MIME type (e.g. `module`) |
| `nomodule` | Fallback for non-ES-module browsers |
| `nonce` | CSP nonce |
| `integrity` / `integrityValue` | SRI hash |
| `crossorigin` | CORS attribute |

### 1.3 `<mai:scss>` — SCSS compilation & inclusion
**Deleted file:** `Classes/ViewHelpers/ScssViewHelper.php`

Compiles SCSS server-side and registers the result as a CSS asset. Inherits all CSS arguments
plus:

| Argument | Purpose |
|---|---|
| `importPaths` | Additional SCSS import directories |

Cache is auto-invalidated when source files change.

---

## 2. Image & Media ViewHelpers

### 2.1 `<mai:image>` — Responsive image (`<img srcset>`)
**Deleted file:** `Classes/ViewHelpers/ImageViewHelper.php`

Generates a responsive `<img>` with `srcset` / `sizes`, lazy loading, priority fetch hints, and
optional format conversion (WebP / AVIF).

Key arguments: `image`, `alt`, `width`, `height`, `srcset`, `sizes`, `lazyloading`, `preload`,
`preloadMedia`, `fetchPriority`, `quality`, `fileExtension`, `decoding`, `crossorigin`, `nonce`,
`integrity`.

### 2.2 `<mai:picture>` — Art-directed responsive picture
**Deleted file:** `Classes/ViewHelpers/PictureViewHelper.php`

Wraps one or more `<mai:picture.source>` children inside a `<picture>` element with a fallback
`<img>`. Supports per-breakpoint format overrides and lazy loading.

### 2.3 `<mai:picture.source>` — Picture source element
**Deleted file:** `Classes/ViewHelpers/Picture/SourceViewHelper.php`

Used inside `<mai:picture>` to define a breakpoint-specific `<source>` with its own `srcset`,
`sizes`, `media`, `formats` and `quality`.

### 2.4 `<mai:figure>` — Semantic figure wrapper
**Deleted file:** `Classes/ViewHelpers/FigureViewHelper.php`

Wraps image ViewHelpers in a `<figure>` / `<figcaption>` pair.

---

## 3. SVG ViewHelpers

### 3.1 `<mai:svgSprite>` — SVG sprite `<use>` reference
**Deleted file:** `Classes/ViewHelpers/SvgSpriteViewHelper.php`

Emits `<svg><use href="…#symbol-id">` pointing at the centrally-served sprite. Symbols are
auto-discovered from `Configuration/SpriteIcons.php` files across installed extensions.

### 3.2 `<mai:svgInline>` — Inline SVG embedding
**Deleted file:** `Classes/ViewHelpers/SvgInlineViewHelper.php`

Reads an SVG file and outputs its markup inline, enabling CSS/JS targeting of internal nodes.
Result is stored in the `maispace_assets` TYPO3 cache.

---

## 4. Resource Hints & Animations

### 4.1 `<mai:hint>` — Resource hints
**Deleted file:** `Classes/ViewHelpers/HintViewHelper.php`

Emits `<link rel="…">` hints into `<head>`: `preconnect`, `dns-prefetch`, `preload`,
`prefetch`, `modulepreload`.

### 4.2 `<mai:lottie>` — Lottie animation
**Deleted file:** `Classes/ViewHelpers/LottieViewHelper.php`

Renders a `<lottie-player>` custom element with configurable animation source and player script
URL (via TypoScript).

---

## 5. Core Services

### 5.1 `AssetProcessingService`
**Deleted file:** `Classes/Service/AssetProcessingService.php` (~963 lines)

Central orchestrator for CSS/JS/SCSS processing. Responsibilities:

- Resolve `EXT:` and relative paths to absolute filesystem paths
- Detect external URLs and handle them separately
- Trigger minification via `matthiasmullie/minify`
- Compute SHA-384 SRI hashes for both local and inline content
- Build stable cache identifiers from path + mtime + configuration
- Fire PSR-14 events after each processing step
- Register the final asset with TYPO3's `PageRenderer`

### 5.2 `ScssCompilerService`
**Deleted file:** `Classes/Service/ScssCompilerService.php` (~116 lines)

Wraps `scssphp/scssphp`. Compiles SCSS source to CSS, supports configurable import paths, and
stores results in the TYPO3 caching framework.

### 5.3 `ImageRenderingService`
**Deleted file:** `Classes/Service/ImageRenderingService.php` (~547 lines)

Handles all image processing. Responsibilities:

- Detect MIME type of source file
- Generate multiple `ProcessedFile` variants for srcset
- Render `<source>` and `<img>` tag markup
- Emit `Link: <…>; rel=preload` HTTP headers for priority images

### 5.4 `ChromiumCdpClient`
**Deleted file:** `Classes/Service/ChromiumCdpClient.php` (~603 lines)

Communicates with a headless Chromium process via the Chrome DevTools Protocol (CDP) to
extract above-the-fold (critical) CSS and JS for a given URL. Used by the CLI extraction
command.

### 5.5 `CriticalAssetService`
**Deleted file:** `Classes/Service/CriticalAssetService.php` (~190 lines)

Manages the caching and retrieval of previously extracted critical CSS/JS blocks. Works
together with `ChromiumCdpClient` and the inline middleware.

### 5.6 `AssetCacheManager`
**Deleted file:** `Classes/Cache/AssetCacheManager.php` (~143 lines)

Centralises cache-key construction for CSS, JS, SCSS and SVG assets so that all services
use a consistent, collision-free naming scheme.

---

## 6. Registries

### 6.1 `SpriteIconRegistry`
**Deleted file:** `Classes/Registry/SpriteIconRegistry.php` (~418 lines)

A `SingletonInterface` service that:

- Auto-discovers symbol definitions from `Configuration/SpriteIcons.php` in every loaded
  extension
- Validates symbol IDs and SVG sources
- Supports per-site scoping via a `sites` key
- Builds the final assembled sprite SVG on demand

### 6.2 `FontRegistry`
**Deleted file:** `Classes/Registry/FontRegistry.php` (~289 lines)

A `SingletonInterface` service that:

- Auto-discovers font definitions from `Configuration/Fonts.php` in every loaded extension
- Detects font format from file extension
- Supports per-site scoping
- Emits `<link rel="preload">` entries for registered fonts

---

## 7. PSR-14 Event System

The previous version exposed seven events (with corresponding example listeners) that let
integrators hook into every processing stage without patching core code.

### 7.1 Events

| Event class | Fired when | Key methods |
|---|---|---|
| `AfterCssProcessedEvent` | CSS minification complete | `getProcessedCss()`, `setProcessedCss()`, view-helper arguments |
| `AfterJsProcessedEvent` | JS minification complete | `getProcessedJs()`, `setProcessedJs()`, view-helper arguments |
| `AfterScssCompiledEvent` | SCSS compilation complete | `getCompiledCss()`, `setCompiledCss()`, original source |
| `BeforeSpriteSymbolRegisteredEvent` | Before each SVG symbol is added | rename / veto individual symbols |
| `AfterSpriteBuiltEvent` | Sprite SVG assembled | inspect / replace assembled markup |
| `BeforeImageProcessingEvent` | Before image variant generation | modify instructions, force formats, skip processing |
| `AfterImageProcessedEvent` | After image variant generation | inspect `ProcessedFile` results, trigger CDN warm-up |
| `AfterCriticalCssExtractedEvent` | Critical CSS extraction complete | post-process above-the-fold content |

### 7.2 Example listeners (deleted)

- `AfterCssProcessedEventListener`
- `AfterJsProcessedEventListener`
- `AfterScssCompiledEventListener`
- `BeforeSpriteSymbolRegisteredEventListener`
- `AfterSpriteBuiltEventListener`
- `BeforeImageProcessingEventListener`
- `AfterImageProcessedEventListener`
- `FontPreloadEventListener` (core listener, not just an example)

---

## 9. CLI Commands

### 9.1 `CriticalCssExtractCommand`
**Deleted file:** `Classes/Command/CriticalCssExtractCommand.php` (~422 lines)

```
php vendor/bin/typo3 maispace:assets:critical:extract
```

Crawls site pages using headless Chromium to extract above-the-fold CSS and JS. Options:

- Mobile and desktop viewport extraction in a single pass
- Workspace and language filtering
- Site-scoped runs via `--site` option
- Results stored by `CriticalAssetService`

### 9.2 `WarmupCommand`
**Deleted file:** `Classes/Command/WarmupCommand.php` (~136 lines)

```
php vendor/bin/typo3 maispace:assets:warmup
```

Triggers asset cache warm-up at deploy time so the first real request is served from cache.

---

## 10. Exception Hierarchy

**All deleted.** The current codebase throws generic exceptions. A typed hierarchy should be
restored:

| Class | Thrown when |
|---|---|
| `AssetException` | Base class for all extension exceptions |
| `AssetFileNotFoundException` | Referenced asset file does not exist |
| `AssetWriteException` | Cannot write processed asset to disk/cache |
| `AssetCompilationException` | SCSS compilation failure |
| `InvalidAssetConfigurationException` | Bad TypoScript or ViewHelper configuration |
| `InvalidImageInputException` | Unsupported or missing image source |

---

## 11. TypoScript Configuration

The previous version provided a comprehensive TypoScript settings tree. A minimal skeleton to
be restored:

```typoscript
plugin.tx_maispace_assets {
    settings {
        css {
            minify       = 1
            deferred     = 0
            outputDir    = typo3temp/assets/css/
            identifierPrefix =
        }
        js {
            minify       = 1
            defer        = 1
            outputDir    = typo3temp/assets/js/
            identifierPrefix =
        }
        scss {
            minify        = 1
            cacheLifetime = 86400
            defaultImportPaths =
        }
        image {
            lazyloading        = 1
            lazyloadWithClass  = lazyload
            forceFormat        =
            alternativeFormats = webp
        }
        fonts {
            preload = 1
        }
        lottie {
            playerSrc =
        }
        svgSprite {
            routePath      = /typo3temp/assets/sprites/
            symbolIdPrefix = icon-
            cache          = 1
        }
        compression {
            enable = 1
            brotli = 1
            gzip   = 1
        }
        criticalCss {
            enable      = 0
            chromiumBin = /usr/bin/chromium
            mobile {
                width  = 390
                height = 844
            }
            desktop {
                width  = 1440
                height = 900
            }
            layer =
        }
    }
}
```

---

## 12. Configuration Discovery Files

### 12.1 `Configuration/SpriteIcons.php`
Each extension can ship this file to register SVG symbols into the global sprite:

```php
return [
    'sites' => ['*'],   // site identifiers or '*' for all
    'icons' => [
        'arrow-right' => 'EXT:my_ext/Resources/Public/Icons/arrow-right.svg',
        // …
    ],
];
```

### 12.2 `Configuration/Fonts.php`
Each extension can ship this file to register web fonts for preloading:

```php
return [
    'sites' => ['*'],
    'fonts' => [
        [
            'src'  => 'EXT:my_ext/Resources/Public/Fonts/Inter.woff2',
            'type' => 'font/woff2',   // auto-detected if omitted
        ],
        // …
    ],
];
```

---

## 13. Test Suite & CI

**All deleted** in `630f7c2`. Needs to be restored:

### Unit / functional tests
- `AssetCacheManagerTest`
- `AssetProcessingServiceTest` + `AssetProcessingServiceExceptionTest`
- `ImageRenderingServiceTest` + `ImageRenderingServiceExceptionTest`
- `SvgSpriteMiddlewareTest`
- `SpriteIconRegistryExceptionTest`

### CI pipeline
- `.github/workflows/ci.yml` — GitHub Actions workflow
- `phpunit.xml.dist` — PHPUnit configuration
- `phpstan.neon` — PHPStan static analysis configuration
- `typoscript-lint.yml` — TypoScript linting configuration

---

## 14. Documentation

All RST documentation was deleted. Files to be restored / rewritten:

| File | Content |
|---|---|
| `Documentation/Introduction.rst` | Extension overview and requirements |
| `Documentation/Installation.rst` | Composer install, TypoScript include |
| `Documentation/ViewHelpers.rst` | Full ViewHelper reference with examples |
| `Documentation/Configuration.rst` | TypoScript settings reference (~636 lines) |
| `Documentation/Events.rst` | PSR-14 event system with listener examples (~734 lines) |
| `Documentation/Compression.rst` | Web server compression setup guide |
| `Documentation/Changelog.rst` | Full changelog (~495 lines) |

---

## Prioritised Implementation Order

Based on dependency relationships and user-facing impact:

1. **Exception hierarchy** — prerequisite for all services (low effort, high value)
2. **`AssetCacheManager`** — prerequisite for all caching
3. **`AssetProcessingService`** + **`ScssCompilerService`** — core asset pipeline
4. **`<mai:css>` / `<mai:js>` / `<mai:scss>` ViewHelpers** — primary integrator touch-points
5. **PSR-14 events** (AfterCssProcessed, AfterJsProcessed, AfterScssCompiled) — extensibility
6. **`ImageRenderingService`** — image pipeline
7. **`<mai:image>` / `<mai:picture>` / `<mai:picture.source>` / `<mai:figure>` ViewHelpers**
8. **`SpriteIconRegistry`** + `<mai:svgSprite>` + `<mai:svgInline>` + `SvgSpriteMiddleware`
9. **`FontRegistry`** + `<mai:hint>` + `<mai:lottie>`
11. **CLI commands** (`critical:extract`, `warmup`)
12. **TypoScript configuration** (expand alongside each step above)
13. **Configuration discovery** (`SpriteIcons.php`, `Fonts.php`)
14. **Test suite & CI** (ideally written alongside each feature, not at the end)
15. **Documentation** (RST pages, one per restored area)
