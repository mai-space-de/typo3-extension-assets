# maispace/assets — TYPO3 Asset ViewHelpers

[![CI](https://github.com/mai-space-de/typo3-extension-assets/actions/workflows/ci.yml/badge.svg)](https://github.com/mai-space-de/typo3-extension-assets/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)
[![TYPO3](https://img.shields.io/badge/TYPO3-13.4%20LTS-orange)](https://typo3.org/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)

A TYPO3 extension that provides Fluid ViewHelpers for CSS, JavaScript, SCSS, images, SVG sprites, Lottie animations, and web font preloading — all from Fluid templates, with performance-first defaults.

**Requires:** TYPO3 13.4 LTS · PHP 8.2+

---

## Features at a glance

| Feature | ViewHelper / API |
|---|---|
| CSS from file, inline, or external URL | `<mai:css>` |
| JavaScript from file, inline, or external URL | `<mai:js>` |
| SCSS compiled server-side (no Node.js) | `<mai:scss>` |
| Import maps (JSON unmangled, always synchronous) | `<mai:js type="importmap">` |
| Legacy bundle differential loading | `<mai:js nomodule="true">` |
| Resource hints (preconnect, dns-prefetch, modulepreload, preload…) | `<mai:hint>` |
| Responsive `<img>` with lazy load, preload, srcset, decoding, crossorigin | `<mai:image>` |
| Responsive `<picture>` with per-breakpoint sources, srcset, quality | `<mai:picture>` + `<mai:picture.source>` |
| Automatic WebP/AVIF `<source>` sets in `<picture>` | `formats="avif, webp"` or `image.alternativeFormats` |
| Global image format conversion (WebP/AVIF) | `image.forceFormat` TypoScript / `fileExtension` argument |
| Lottie animations via `<lottie-player>` web component | `<mai:lottie>` |
| Inline SVG embedding from file (CSS/JS accessible) | `<mai:svgInline>` |
| SVG sprite served from a cacheable URL | `<mai:svgSprite>` + `Configuration/SpriteIcons.php` |
| Web font `<link rel="preload">` in `<head>` | `Configuration/Fonts.php` |
| CSP nonce on inline `<style>` / `<script>` | `nonce` argument (auto-detected from TYPO3 request) |
| SRI integrity on local assets | `integrity="true"` argument |
| SRI integrity on external assets | `integrityValue="sha384-..."` argument |
| Semantic `<figure>/<figcaption>` wrapper | `<mai:figure>` |
| Multi-site scoping for sprites and fonts | `'sites'` key in config files |
| Deploy-time cache warm-up | `php vendor/bin/typo3 maispace:assets:warmup` |
| PSR-14 events at every processing stage | `Classes/Event/` |

---

## Installation

```bash
composer require maispace/assets
```

Include the TypoScript setup in your site package:

```typoscript
@import 'EXT:maispace_assets/Configuration/TypoScript/setup.typoscript'
```

No extension manager configuration, no ext_tables.php boilerplate.

---

## CSS & JavaScript

Include assets inline or from a file. Local assets are minified, cached in `typo3temp/`, and registered with TYPO3's AssetCollector. External URLs are passed through directly — no local copy is made.

```html
<!-- CSS from file (deferred by default via media="print" swap) -->
<mai:css src="EXT:theme/Resources/Public/Css/app.css" />

<!-- Critical CSS inlined in <head> -->
<mai:css identifier="critical" priority="true" inline="true">
    body { margin: 0; font-family: sans-serif; }
</mai:css>

<!-- External CSS (e.g. Google Fonts) — passed through, not processed locally -->
<mai:css src="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap"
         deferred="false" />

<!-- External CSS with pre-computed SRI hash -->
<mai:css src="https://cdn.example.com/vendor.css"
         integrityValue="sha384-Fo3rlrZj/k7ujTeHg/9LZlB9xHqgSjQKtFXpgzH/vX8AAIM5B4YX7d3/9g==" />

<!-- JS (defer="true" by default) -->
<mai:js src="EXT:theme/Resources/Public/JavaScript/app.js" />

<!-- ES module -->
<mai:js src="EXT:theme/Resources/Public/JavaScript/app.js" type="module" />

<!-- External analytics snippet -->
<mai:js src="https://cdn.example.com/analytics.js" async="true" />

<!-- Legacy bundle for browsers without ES module support -->
<mai:js src="EXT:theme/Resources/Public/JavaScript/legacy.js" nomodule="true" />
```

### Import maps

Import maps must be inline JSON and must load synchronously before any `type="module"` script. The ViewHelper enforces this automatically: no minification, no `defer`, always placed in `<head>`.

```html
<mai:js type="importmap">
{
    "imports": {
        "lodash": "/node_modules/lodash-es/lodash.js",
        "app": "/assets/app.js"
    }
}
</mai:js>
```

---

## SCSS

Compile SCSS to CSS server-side using [scssphp](https://scssphp.github.io/scssphp/) — no Node.js required.

```html
<mai:scss src="EXT:theme/Resources/Private/Scss/main.scss" />

<!-- Additional @import paths -->
<mai:scss src="EXT:theme/Resources/Private/Scss/main.scss"
         importPaths="EXT:theme/Resources/Private/Scss/Partials" />

<!-- SRI integrity hash on the compiled stylesheet -->
<mai:scss src="EXT:theme/Resources/Private/Scss/main.scss" integrity="true" />

<!-- Critical SCSS inlined in <head> with CSP nonce (auto-detected from TYPO3 request) -->
<mai:scss identifier="critical" priority="true" inline="true">
    body { margin: 0; font-family: sans-serif; }
</mai:scss>
```

Cache is automatically invalidated when the source file changes (`filemtime`).

**Available arguments:** `src`, `identifier`, `priority`, `minify`, `inline`, `deferred`, `media`, `importPaths`, `nonce`, `integrity`, `crossorigin`.

---

## Resource Hints

Emit `<link>` resource hints into `<head>` to warm up connections and pre-fetch critical resources. All hints are injected via PageRenderer and always land in `<head>`.

```html
<!-- Warm up TCP+TLS to a CDN origin (cheapest way to speed up cross-origin assets) -->
<mai:hint rel="preconnect" href="https://fonts.gstatic.com" crossorigin="anonymous" />

<!-- DNS-only warm-up (no TLS — even cheaper, use for less-critical origins) -->
<mai:hint rel="dns-prefetch" href="https://cdn.example.com" />

<!-- Preload an ES module and all its static imports in parallel -->
<mai:hint rel="modulepreload" href="/assets/app.js" />

<!-- Preload a web font (crossorigin is required for fonts) -->
<mai:hint rel="preload" href="/fonts/Inter.woff2"
         as="font" type="font/woff2" crossorigin="anonymous" />

<!-- Conditional image preload scoped to a viewport size -->
<mai:hint rel="preload" href="/images/hero-mobile.webp"
         as="image" media="(max-width: 767px)" />

<!-- Prefetch a resource likely needed on the next navigation -->
<mai:hint rel="prefetch" href="/next-page.html" />
```

---

## Images

Process images via TYPO3's native ImageService (supports WebP conversion, cropping, etc). Accept FAL UIDs, File/FileReference objects, or EXT: paths.

### `<mai:image>` — single `<img>`

```html
<!-- From a sys_file_reference UID -->
<mai:image image="{file.uid}" alt="{file.alternative}" width="800" />

<!-- Hero image: preloaded, high priority, no lazy -->
<mai:image image="{hero}" alt="{heroAlt}" width="1920"
          lazyloading="false" preload="true" fetchPriority="high" />

<!-- Hero preload scoped to desktop viewports (avoids loading on mobile) -->
<mai:image image="{heroDesktop}" alt="{alt}" width="1920"
          preload="true" preloadMedia="(min-width: 768px)" lazyloading="false" />

<!-- Lazy load with a JS-hook class (e.g. for lazysizes) -->
<mai:image image="{img}" alt="{alt}" width="427c" height="240"
          lazyloadWithClass="lazyload" />

<!-- Explicit JPEG quality (1–100); 0 = ImageMagick/GM default -->
<mai:image image="{img}" alt="{alt}" width="800" quality="75" />

<!-- Force WebP output -->
<mai:image image="{img}" alt="{alt}" width="800" fileExtension="webp" />

<!-- Non-blocking decode (ideal for below-the-fold images) -->
<mai:image image="{img}" alt="{alt}" width="800" decoding="async" />

<!-- CORS-enabled image (needed for canvas/WebGL pixel access) -->
<mai:image image="{img}" alt="{alt}" width="800" crossorigin="anonymous" />

<!-- Hero: preload with full metadata so the browser fetches the right variant -->
<mai:image image="{hero}" alt="{heroAlt}" width="1920"
          preload="true" fetchPriority="high" lazyloading="false"
          srcset="800, 1200, 1920"
          sizes="(max-width: 768px) 100vw, 1920px" />
```

Width/height notation: `800` (exact) · `800c` (centre crop) · `800m` (max, proportional)

**Available arguments:** `image`, `alt`, `width`, `height`, `quality`, `lazyloading`, `lazyloadWithClass`, `fetchPriority`, `decoding`, `crossorigin`, `preload`, `preloadMedia`, `srcset`, `sizes`, `fileExtension`, `class`, `id`, `title`, `additionalAttributes`.

### `<mai:picture>` + `<mai:picture.source>` — responsive `<picture>`

Sources are configured inline in the template — no central YAML file needed.

```html
<mai:picture image="{imageRef}" alt="{alt}" width="1200" lazyloadWithClass="lazyload">
    <mai:picture.source media="(min-width: 980px)" width="1200" height="675" />
    <mai:picture.source media="(min-width: 768px)" width="800" height="450" />
    <mai:picture.source media="(max-width: 767px)" width="400" height="225" />
</mai:picture>

<!-- CSS class on the <picture> wrapper independent of the fallback <img> -->
<mai:picture image="{img}" alt="{alt}" width="1200"
             class="picture-wrapper" imgClass="content-image" imgId="hero-img">
    <mai:picture.source media="(min-width: 768px)" width="1200" />
</mai:picture>

<!-- Hero picture: preload scoped to desktop viewports -->
<mai:picture image="{hero}" alt="{alt}" width="1920" lazyloading="false"
             preload="true" preloadMedia="(min-width: 768px)" fetchPriority="high"
             imgDecoding="async">
    <mai:picture.source media="(min-width: 768px)" width="1920" />
    <mai:picture.source media="(max-width: 767px)" width="600" />
</mai:picture>

<!-- Responsive srcset per source breakpoint -->
<mai:picture image="{imageRef}" alt="{alt}" width="1200">
    <mai:picture.source media="(min-width: 768px)"
                        srcset="800, 1200, 1600"
                        sizes="(min-width: 1200px) 1200px, 100vw" />
    <mai:picture.source media="(max-width: 767px)"
                        srcset="400, 600"
                        sizes="100vw" />
</mai:picture>
```

Each `<mai:picture.source>` processes the image independently to the specified dimensions. Override the image for a specific breakpoint with the `image` argument.

**`<picture>` vs `<img>` attributes:** `class` and `additionalAttributes` apply to the outer `<picture>` element. Use `imgClass`, `imgId`, `imgTitle`, `imgAdditionalAttributes`, `imgDecoding`, and `imgCrossorigin` to target the fallback `<img>` independently.

### Automatic WebP/AVIF source sets

The `formats` argument renders one `<source>` per format (most capable first), then the fallback `<img>`. No template duplication needed.

```html
<mai:picture image="{img}" alt="{alt}" width="1200" formats="avif, webp">
    <mai:picture.source media="(min-width: 768px)" width="1200" formats="avif, webp" />
    <mai:picture.source media="(max-width: 767px)" width="400" formats="avif, webp" />
</mai:picture>
```

Output:
```html
<picture>
  <source srcset="…1200.avif" media="(min-width: 768px)" type="image/avif">
  <source srcset="…1200.webp" media="(min-width: 768px)" type="image/webp">
  <source srcset="…1200.jpg"  media="(min-width: 768px)" type="image/jpeg">
  <source srcset="…400.avif"  media="(max-width: 767px)" type="image/avif">
  <source srcset="…400.webp"  media="(max-width: 767px)" type="image/webp">
  <source srcset="…400.jpg"   media="(max-width: 767px)" type="image/jpeg">
  <img src="…1200.jpg" …>
</picture>
```

Enable globally via TypoScript so all `<picture>` elements get format sources without per-template changes:

```typoscript
plugin.tx_maispace_assets.image.alternativeFormats = avif, webp
```

### Image quality

The `quality` argument is available on `<mai:image>`, `<mai:picture>`, and `<mai:picture.source>`. It applies to all processed variants (including format alternatives).

```html
<mai:picture image="{img}" alt="{alt}" width="1200" quality="80" formats="avif, webp">
    <mai:picture.source media="(min-width: 768px)" width="1200" quality="80" />
</mai:picture>
```

### `<mai:figure>` — semantic figure wrapper

```html
<mai:figure caption="{file.description}" class="article-figure">
    <mai:picture image="{file}" alt="{file.alternative}" width="1200">
        <mai:picture.source media="(min-width: 768px)" width="1200" />
        <mai:picture.source media="(max-width: 767px)" width="600" />
    </mai:picture>
</mai:figure>
```

---

## Lottie Animations

Render Lottie JSON animations using the `<lottie-player>` web component. The player script is registered once via AssetCollector as a `type="module"` script (non-blocking).

```html
<!-- Basic looping animation -->
<mai:lottie src="EXT:theme/Resources/Public/Animations/hero.json"
           width="400px" height="400px" />

<!-- One-shot (no loop), explicit size -->
<mai:lottie src="EXT:theme/Resources/Public/Animations/checkmark.json"
           loop="false" autoplay="true" width="80px" height="80px" />

<!-- Bounce mode with player controls -->
<mai:lottie src="/animations/wave.json"
           mode="bounce" controls="true" width="300px" />

<!-- External animation JSON from a CDN -->
<mai:lottie src="https://assets.example.com/animations/hero.json"
           width="100%" height="500px" />

<!-- Skip player registration (you include the script separately) -->
<mai:lottie src="/animations/icon.json" playerSrc="" width="48px" height="48px" />
```

Configure the player script URL globally via TypoScript (pin a version in production):

```typoscript
plugin.tx_maispace_assets.lottie.playerSrc = https://unpkg.com/@lottiefiles/lottie-player@2.0.8/dist/lottie-player.js
```

Or set it per element:

```html
<mai:lottie src="/animations/hero.json"
           playerSrc="EXT:theme/Resources/Public/Vendor/lottie-player.js"
           width="600px" />
```

Pass `playerSrc=""` to skip auto-registration entirely when you include the player via another mechanism.

**Available arguments:** `src`, `autoplay`, `loop`, `controls`, `speed`, `direction`, `mode` (`"normal"` / `"bounce"`), `renderer` (`"svg"` / `"canvas"` / `"html"`), `background`, `width`, `height`, `class`, `playerSrc`, `playerIdentifier`, `additionalAttributes`.

---

## Inline SVG

Embed an SVG file directly as inline markup — required when the SVG needs CSS styling (`fill: currentColor`), JavaScript interaction, or must render without a separate network request.

```html
<!-- Decorative (aria-hidden="true" by default) -->
<mai:svgInline src="EXT:theme/Resources/Public/Icons/logo.svg"
              class="logo" width="120" height="40" />

<!-- Meaningful SVG with accessible label -->
<mai:svgInline src="EXT:theme/Resources/Public/Icons/checkmark.svg"
              aria-label="Success" width="24" height="24" />

<!-- Custom title element for screen readers -->
<mai:svgInline src="EXT:theme/Resources/Public/Icons/logo.svg"
              title="Company Logo" aria-label="Company Logo" />
```

The processed markup is cached in the `maispace_assets` cache. The source file must be a trusted filesystem path (EXT: notation or site-relative) — user-supplied SVG is not safe to embed inline without sanitization.

---

## SVG Sprites

Icons are registered via `Configuration/SpriteIcons.php` in any extension. The sprite is assembled once, cached, and served from a dedicated HTTP endpoint with long-lived browser cache headers.

```php
// EXT:my_sitepackage/Configuration/SpriteIcons.php
return [
    'icon-arrow' => ['src' => 'EXT:my_sitepackage/Resources/Public/Icons/arrow.svg'],
    'icon-close' => ['src' => 'EXT:my_sitepackage/Resources/Public/Icons/close.svg'],
];
```

```html
<!-- Decorative icon (aria-hidden="true" by default) -->
<mai:svgSprite use="icon-arrow" width="24" height="24" class="icon" />

<!-- Meaningful icon -->
<mai:svgSprite use="icon-close" aria-label="Close dialog" width="20" height="20" />
```

The sprite endpoint (`/maispace/sprite.svg` by default) is cached by the browser for one year (`Cache-Control: public, max-age=31536000, immutable`) and supports conditional GET via ETag.

---

## Font Preloading

Register web fonts in `Configuration/Fonts.php` to automatically emit `<link rel="preload" as="font" crossorigin>` in `<head>`. Fonts are served from their stable public URL — no temp file generation.

```php
// EXT:my_sitepackage/Configuration/Fonts.php
return [
    'my-font-regular' => [
        'src' => 'EXT:my_sitepackage/Resources/Public/Fonts/MyFont-Regular.woff2',
        // 'type' is auto-detected from the extension (.woff2 → font/woff2)
    ],
    'my-font-bold' => [
        'src'     => 'EXT:my_sitepackage/Resources/Public/Fonts/MyFont-Bold.woff2',
        'preload' => false, // register without emitting a preload tag
    ],
];
```

Supported auto-detected types: `.woff2` · `.woff` · `.ttf` · `.otf`

---

## Multi-site Scoping

In a TYPO3 instance with multiple sites, use the `'sites'` key to restrict fonts or SVG symbols to specific sites. Entries without `'sites'` are served on all sites.

```php
// SpriteIcons.php or Fonts.php
return [
    'shared-icon' => [
        'src' => 'EXT:shared/Resources/Public/Icons/info.svg',
        // no 'sites' key → available on all sites
    ],
    'icon-brand-a-logo' => [
        'src'   => 'EXT:brand_a/Resources/Public/Icons/logo.svg',
        'sites' => ['brand-a'],
    ],
    'icon-brand-b-logo' => [
        'src'   => 'EXT:brand_b/Resources/Public/Icons/logo.svg',
        'sites' => ['brand-b', 'brand-b-staging'],
    ],
];
```

The site identifier matches the folder name under `config/sites/{identifier}/`. Each site gets its own cached sprite — one build per site, then served indefinitely from cache.

---

## TypoScript Configuration

```typoscript
plugin.tx_maispace_assets {
    css {
        minify = 1
        deferred = 1
        outputDir = typo3temp/assets/maispace_assets/css/
        identifierPrefix = maispace_
    }
    js {
        minify = 1
        defer = 1
        outputDir = typo3temp/assets/maispace_assets/js/
        identifierPrefix = maispace_
    }
    scss {
        minify = 1
        cacheLifetime = 0
        defaultImportPaths =
    }
    image {
        lazyloading = 1
        lazyloadWithClass =        # e.g. "lazyload" for lazysizes
        forceFormat =              # e.g. "webp" to convert all images globally
        alternativeFormats =       # e.g. "avif, webp" for automatic <source> sets
    }
    fonts {
        preload = 1                # 0 to suppress all font preload tags globally
    }
    lottie {
        playerSrc =                # URL or EXT: path to lottie-player.js (empty = skip)
    }
    svgSprite {
        routePath = /maispace/sprite.svg
        symbolIdPrefix = icon-
        cache = 1
    }
}
```

**Debug mode** — all minification and deferral is automatically disabled when a backend user is logged in and `?debug=1` is in the URL (included in `setup.typoscript`, no manual setup needed).

---

## PSR-14 Events

Hook into asset processing by registering listeners in your site package's `Configuration/Services.yaml`:

| Event | When |
|---|---|
| `AfterCssProcessedEvent` | After CSS is minified, before caching |
| `AfterJsProcessedEvent` | After JS is minified, before caching |
| `AfterScssCompiledEvent` | After SCSS is compiled, before caching |
| `BeforeSpriteSymbolRegisteredEvent` | Per symbol during auto-discovery — can rename, modify, or veto |
| `AfterSpriteBuiltEvent` | After full sprite XML is assembled, before caching |
| `BeforeImageProcessingEvent` | Before each image is processed — modify instructions, force WebP/AVIF, or skip |
| `AfterImageProcessedEvent` | After each image is processed — inspect result, replace ProcessedFile, log metrics |

Seven example listeners with full documentation are in `Classes/EventListener/` (inactive by default). Copy the relevant `Services.yaml` block to your site package to activate one.

---

## Registering Fonts and Icons from Your Extension

Both registries use the same auto-discovery pattern — no `ext_localconf.php` registration needed:

| File | Purpose |
|---|---|
| `EXT:my_ext/Configuration/SpriteIcons.php` | Register SVG symbols for the sprite |
| `EXT:my_ext/Configuration/Fonts.php` | Register fonts for `<link rel="preload">` |

The registries scan all loaded TYPO3 extensions for these files on first use. Later-loaded extensions win on key conflicts, so site packages can override vendor icons/fonts.

---

## Development

### Running tests

Install dev dependencies, then run the PHPUnit test suite:

```bash
composer install
composer test
```

Or with the long form (verbose testdox output):

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist --testdox
```

**Test structure:**

| File | What it tests |
|---|---|
| `Tests/Unit/Cache/AssetCacheManagerTest.php` | Key-building methods, cache delegation |
| `Tests/Unit/Service/AssetProcessingServiceTest.php` | `isExternalUrl`, `buildIdentifier`, `buildIntegrityAttrs`, `resolveFlag` |
| `Tests/Unit/Service/ImageRenderingServiceTest.php` | `detectMimeType`, `renderSourceTag`, `renderImgTag`, `addImagePreloadHeader` |

All tests are pure unit tests — no database, no TYPO3 installation required. PHPUnit mocks are used for all TYPO3 service dependencies.

### CI

The GitHub Actions workflow (`.github/workflows/ci.yml`) runs on every push and pull request:

| Job | What it checks |
|---|---|
| `composer-validate` | `composer.json` is valid and well-formed |
| `unit-tests` | PHPUnit suite across PHP 8.2 / 8.3 × TYPO3 13.4 |
| `static-analysis` | PHPStan (`phpstan.neon`, level max) |
| `code-style` | EditorConfig (`armin/editorconfig-cli`) + PHP-CS-Fixer (`.php-cs-fixer.php`) |
| `typoscript-lint` | TypoScript style/structure (`typoscript-lint.yml`) |

---

## License

GPL-2.0-or-later
