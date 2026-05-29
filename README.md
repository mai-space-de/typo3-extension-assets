# maispace/mai-assets — TYPO3 Extension
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)
[![TYPO3](https://img.shields.io/badge/TYPO3-13.4%20LTS-orange)](https://typo3.org/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)

The canonical asset pipeline for the entire extension set. Provides Fluid ViewHelper-based asset inclusion with minification, SCSS compilation, and SVG sprite building. Also manages the TYPO3 file abstraction layer via `cms-filelist` and `cms-filemetadata`. All other extensions that need SCSS compilation or asset minification depend on this extension rather than pulling in `scssphp` or minification libraries directly.

**Requires:** TYPO3 13.4 LTS / 14.1 · PHP 8.2+

---

## Installation

```bash
composer require maispace/mai-assets
```

---

## ViewHelpers

The extension registers the `mai` namespace globally via TypoScript:

```
config.namespaces.mai = Maispace\MaiAssets\ViewHelpers
```

Once the extension is loaded, all ViewHelpers below are available in Fluid templates with the `mai:` prefix.
If you need to declare the namespace manually (e.g. in a standalone template), add:

```html
{namespace mai=Maispace\MaiAssets\ViewHelpers}
```

---

### CSS & JS ViewHelpers

#### `<mai:css>` — Stylesheet inclusion

Compiles SCSS on demand, minifies CSS, auto-computes SRI hashes, handles critical inlining, and registers
HTTP/103 Early Hints preload candidates. Returns an empty string for non-critical files (the asset is
registered with the TYPO3 `AssetCollector`); returns a `<style>` block for critical files.

| Argument | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `identifier` | string | yes | — | Deduplication key; duplicate identifiers are silently ignored |
| `src` | string | yes | — | `EXT:` path or absolute path to a `.css` or `.scss` file |
| `priority` | bool | | `false` | Render in `<head>` before page CSS when `true`; footer otherwise |
| `minify` | bool | | *global* | Per-call override; inherits `plugin.tx_maiassets.settings.enableMinification` when omitted |
| `critical` | string | | `'auto'` | `'auto'` detect from observer data · `'true'` always inline · `'false'` always link |
| `media` | string | | `'all'` | CSS `media` attribute on the `<link>` tag |
| `nonce` | string | | `''` | CSP nonce injected on the inline `<style>` when the file is critical |
| `integrity` | string | | `''` | SRI hash; auto-computed from the compiled file when empty and the file is local |
| `crossorigin` | string | | `''` | `crossorigin` attribute on the `<link>` tag |

**Critical mode:** when `critical="true"` or the page observer marks the current page as above-fold
critical, the CSS is compiled and inlined as `<style>…</style>`. An Early Hints preload candidate is
registered in both modes.

```html
<!-- Standard linked stylesheet (SCSS compiled and minified automatically) -->
<mai:css identifier="mai-theme-main"
         src="EXT:mai_theme/Resources/Public/Css/main.scss" />

<!-- Force critical inline with CSP nonce -->
<mai:css identifier="mai-theme-critical"
         src="EXT:mai_theme/Resources/Public/Css/critical.scss"
         critical="true"
         nonce="{typo3:security.nonce()}" />

<!-- Print stylesheet -->
<mai:css identifier="mai-print"
         src="EXT:mai_theme/Resources/Public/Css/print.css"
         media="print" />
```

---

#### `<mai:js>` — Script inclusion

Minifies JavaScript, auto-computes SRI hashes, handles critical `fetchpriority`, ES6 modules, and
registers HTTP/103 Early Hints preload or modulepreload candidates. Returns an empty string (the script
is registered with the TYPO3 `AssetCollector`).

| Argument | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `identifier` | string | yes | — | Deduplication key |
| `src` | string | yes | — | `EXT:` path or absolute path to a `.js` file |
| `priority` | bool | | `false` | `true` = inject in `<head>` · `false` = footer |
| `minify` | bool | | *global* | Per-call override; inherits global minification setting when omitted |
| `critical` | string | | `'auto'` | `'auto'` detect · `'true'` add `fetchpriority="high"` · `'false'` normal |
| `defer` | bool | | `true` | Add `defer` attribute; ignored for `type="module"` and critical scripts |
| `async` | bool | | `false` | Add `async` attribute; mutually exclusive with `defer` |
| `type` | string | | `''` | MIME type; set `'module'` for ES6 modules (browser defers automatically) |
| `nomodule` | bool | | `false` | Add `nomodule` for legacy-browser fallback bundles |
| `nonce` | string | | `''` | CSP nonce |
| `integrity` | string | | `''` | SRI hash; auto-computed for local files when empty |
| `crossorigin` | string | | `''` | `crossorigin` attribute |

**Module scripts:** setting `type="module"` enables ES6 module semantics. Early Hints use `modulepreload`
instead of `preload` for these scripts.
**Critical scripts:** `defer` is suppressed and `fetchpriority="high"` is added. An Early Hints preload
candidate is registered.

```html
<!-- Standard deferred script -->
<mai:js identifier="mai-app"
        src="EXT:mai_theme/Resources/Public/JavaScript/app.js" />

<!-- ES6 module -->
<mai:js identifier="mai-module"
        src="EXT:mai_theme/Resources/Public/JavaScript/module.js"
        type="module" />

<!-- Legacy fallback for browsers that do not support modules -->
<mai:js identifier="mai-legacy"
        src="EXT:mai_theme/Resources/Public/JavaScript/legacy.js"
        type="text/javascript"
        nomodule="true" />

<!-- Above-fold critical script -->
<mai:js identifier="mai-slider"
        src="EXT:mai_theme/Resources/Public/JavaScript/slider.js"
        critical="true"
        priority="true" />
```

---

### Image ViewHelpers

#### `<mai:image.responsive>` — Single-tag responsive image

Generates a complete `<picture>` element with AVIF, WebP, and JPEG `srcset` variants per named
breakpoint. Use when the same crop is appropriate for all viewport sizes.

| Argument | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `image` | object | yes | — | FAL `FileReference` or `File` object |
| `breakpoints` | array | yes | — | Associative array of breakpoint label → pixel width, e.g. `{mobile: 400, desktop: 1200}` |
| `sizes` | string | yes | — | `sizes` attribute, e.g. `'(max-width: 767px) 100vw, 50vw'` |
| `critical` | string | | `'auto'` | `'auto'` · `'true'` · `'false'` |
| `elementUid` | int | | `0` | Content element UID for auto-criticality detection |
| `alt` | string | | `''` | Alt text on the fallback `<img>` |
| `class` | string | | `''` | CSS class on the `<img>` element |

When the image is critical, `loading="eager"`, `fetchpriority="high"`, and `decoding="sync"` are set,
and an Early Hints preload candidate for the AVIF source is registered.

```html
<mai:image.responsive
    image="{coverImage}"
    breakpoints="{mobile: 400, tablet: 800, desktop: 1200}"
    sizes="(max-width: 767px) 100vw, (max-width: 1023px) 50vw, 33vw"
    alt="{newsItem.title}"
    elementUid="{data.uid}"
    critical="auto" />
```

Rendered output (simplified):

```html
<picture>
  <source type="image/avif" srcset="/f/…-400.avif 400w, /f/…-1200.avif 1200w" sizes="…">
  <source type="image/webp" srcset="/f/…-400.webp 400w, /f/…-1200.webp 1200w" sizes="…">
  <img src="/f/…-1200.jpeg" srcset="…" sizes="…" alt="…" loading="lazy" decoding="async">
</picture>
```

---

#### `<mai:image.picture>` + `<mai:image.picture.source>` — Art-directed responsive image

Use these two ViewHelpers together when different crops, aspect ratios, or format selections are needed
per breakpoint. `<mai:image.picture>` provides the outer `<picture>` wrapper and resolves criticality;
`<mai:image.picture.source>` children define each `<source>` element.

**`<mai:image.picture>` arguments:**

| Argument | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `image` | object | yes | — | FAL `FileReference` or `File` object |
| `alt` | string | | `''` | Alt text on the fallback `<img>` |
| `width` | int | | `0` | Fallback image pixel width (0 = original width) |
| `height` | int | | `0` | Fallback image pixel height |
| `critical` | string | | `'auto'` | `'auto'` · `'true'` · `'false'` |
| `elementUid` | int | | `0` | Content element UID for auto-criticality detection |
| `quality` | int | | `85` | Fallback image quality (1–100) |
| `fileExtension` | string | | `''` | Fallback image format, e.g. `'jpg'` |
| `crossorigin` | string | | `''` | `crossorigin` attribute on `<img>` |
| `class` | string | | `''` | CSS class on `<img>` |

**`<mai:image.picture.source>` arguments:**

| Argument | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `media` | string | yes | — | CSS media query for this source, e.g. `'(max-width: 767px)'` |
| `srcset` | array | | `[]` | Array of widths to generate, e.g. `{0: 400, 1: 800}` |
| `sizes` | string | | `''` | `sizes` attribute |
| `formats` | array | | `['avif', 'webp']` | Image formats; order determines `<source>` order in the output |
| `quality` | int | | `85` | Image quality (1–100) |
| `width` | int | | `0` | Fallback width |
| `height` | int | | `0` | Fallback height |

`<mai:image.picture.source>` must be a **direct child** of `<mai:image.picture>` and only renders inside
that context. When the parent image is critical, the first AVIF source registers an Early Hints preload
candidate.

```html
<mai:image.picture image="{heroImage}"
                   alt="{data.header}"
                   elementUid="{data.uid}"
                   width="1600"
                   fileExtension="jpg">
  <!-- Mobile: portrait crop -->
  <mai:image.picture.source
      media="(max-width: 767px)"
      srcset="{0: 400, 1: 800}"
      sizes="100vw"
      formats="{0: 'avif', 1: 'webp'}" />
  <!-- Desktop: landscape crop -->
  <mai:image.picture.source
      media="(min-width: 768px)"
      srcset="{0: 1200, 1: 1600}"
      sizes="100vw"
      formats="{0: 'avif', 1: 'webp'}" />
</mai:image.picture>
```

---

#### `<mai:image.figure>` — Semantic figure wrapper

Wraps child content in a `<figure>` element with an optional `<figcaption>`.

| Argument | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `caption` | string | | `''` | Caption text rendered as `<figcaption>`; omitted when empty |
| `class` | string | | `''` | CSS class on `<figure>` |
| `id` | string | | `''` | `id` attribute on `<figure>` |
| `role` | string | | `''` | `role` attribute on `<figure>` |

```html
<mai:image.figure caption="{image.description}" class="gallery-figure">
  <mai:image.responsive
      image="{image}"
      breakpoints="{desktop: 800}"
      sizes="100vw"
      alt="{image.alternative}" />
</mai:image.figure>
```

---

### SVG ViewHelpers

#### `<mai:svg.icon>` — SVG sprite icon

Renders an inline `<svg><use href="#id"></svg>` reference that points to the single shared sprite
injected after `<body>`. The SVG source file is parsed and registered in the sprite on the first render
of each page. Subsequent references to the same identifier use the already-registered symbol.

| Argument | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `identifier` | string | yes | — | Unique sprite symbol ID; used as the `href="#…"` target |
| `source` | string | yes | — | `EXT:` path to the source `.svg` file |
| `label` | string | | `''` | Accessible label; when non-empty renders a meaningful icon with `role="img"` and `<title>` |
| `class` | string | | `''` | CSS class on the `<svg>` element |
| `size` | string | | `'1em'` | Inline `width` / `height` CSS value applied as a `style` attribute |

Icons without a `label` are treated as decorative: `aria-hidden="true"` and `focusable="false"` are
added automatically.

```html
<!-- Decorative icon (aria-hidden) -->
<mai:svg.icon identifier="icon-arrow-right"
              source="EXT:mai_theme/Resources/Public/Icons/arrow-right.svg"
              size="24px" />

<!-- Meaningful icon with accessible label -->
<mai:svg.icon identifier="icon-search"
              source="EXT:mai_theme/Resources/Public/Icons/search.svg"
              label="Search"
              class="nav-icon"
              size="20px" />
```

---

#### `<mai:svg.inline>` — Inline SVG

Inlines the full SVG source directly into the HTML. Output is cached by file hash combined with all
argument values, so repeated calls with identical arguments return the cached result. `width` and
`height` attributes are stripped from the `<svg>` root by default so the element scales via CSS.

| Argument | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `src` | string | yes | — | `EXT:` path or absolute path to the `.svg` file |
| `title` | string | | `''` | Injects a `<title>` as the first child element for screen-reader accessibility |
| `ariaLabel` | string | | `''` | `aria-label` attribute on the `<svg>` root; also sets `role="img"` |
| `class` | string | | `''` | CSS class attribute on the `<svg>` root |
| `stripDimensions` | bool | | `true` | Strip `width` / `height` from the `<svg>` root (recommended; size via CSS) |

```html
<!-- Logo SVG with accessible title -->
<mai:svg.inline src="EXT:mai_theme/Resources/Public/Icons/logo.svg"
                title="BGM Pulheim"
                class="site-logo" />

<!-- Decorative illustration (no accessible name needed) -->
<mai:svg.inline src="EXT:mai_theme/Resources/Public/Illustrations/wave.svg"
                class="section-wave" />
```

---

### Video ViewHelper

#### `<mai:video.video>` — Unified video element

Supports self-hosted files, YouTube and Vimeo privacy-friendly lazy-load facades, and a muted autoplay
background mode. All embed types are lazy-loaded by default (`data-lazy` + `preload="none"` or facade
pattern). Pass exactly one of `file`, `youtubeId`, or `vimeoId`; for `type="background"` only `file`
is supported.

| Argument | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `file` | object | | `null` | FAL `FileReference` for self-hosted video |
| `youtubeId` | string | | `''` | YouTube video ID; embeds via `youtube-nocookie.com` |
| `vimeoId` | string | | `''` | Vimeo video ID; embeds via `player.vimeo.com` |
| `poster` | object | | `null` | FAL `FileReference` used as poster/thumbnail image |
| `isCritical` | bool | | `false` | Above-fold flag for background mode: `true` = eager autoplay, `false` = `data-lazy` |
| `type` | string | | `'content'` | `'content'` — interactive player · `'background'` — muted autoplay loop |
| `title` | string | | `''` | Video title for accessibility (`<button aria-label>` and `<iframe title>`) |
| `class` | string | | `''` | CSS class on the outermost element |

**Self-hosted source order:** AV1 (`.av1.mp4`) → HEVC (`.hevc.mp4`) → H.264 (original). Variant files
are discovered by convention: place `filename.av1.mp4` and `filename.hevc.mp4` alongside the original
`filename.mp4`. Missing variants are silently omitted.

**YouTube / Vimeo facades:** a poster image and play button are rendered initially. A JavaScript click
handler must clone the `<iframe>` from the adjacent `<template>` element and replace the facade. No JS
library is bundled — implement the activation script in `mai_theme`.

**Background videos** use `autoplay muted loop playsinline`. Above-fold videos (`isCritical="true"`)
get `preload="metadata"` and start immediately; below-fold videos get `preload="none" data-lazy` for
deferred loading.

```html
<!-- Self-hosted with poster image -->
<mai:video.video file="{videoFile}"
                 poster="{posterImage}"
                 title="Presentation video"
                 class="content-video" />

<!-- YouTube with privacy-friendly facade -->
<mai:video.video youtubeId="dQw4w9WgXcQ"
                 poster="{customPoster}"
                 title="Watch our introduction"
                 class="embed-video" />

<!-- Vimeo facade -->
<mai:video.video vimeoId="123456789"
                 title="Organisation overview" />

<!-- Autoplay hero background (above-fold) -->
<mai:video.video file="{backgroundVideo}"
                 type="background"
                 isCritical="true"
                 class="hero-video" />

<!-- Lazy background (below-fold) -->
<mai:video.video file="{backgroundVideo}"
                 type="background"
                 class="section-bg-video" />
```

---

## Criticality Resolution

The `AssetCriticalityResolver` service provides ViewHelpers with a single injection point to query
above-fold criticality. Two page-level methods are available:

### `pageHasObserverData(int $pageUid): bool`

Returns `true` when **any** viewport bucket (e.g. only desktop) has reported critical UIDs for the
page. This is the permissive check — used by `<mai:css>` and `<mai:js>` in `critical="auto"` mode
to start inlining as soon as the first visitor's browser reports above-fold elements.

### `pageHasCompleteObserverData(int $pageUid): bool`

Returns `true` only when **all** configured viewport buckets (mobile, tablet, desktop) have been
reported AND at least one bucket contains non-empty critical UIDs. This is the conservative check —
wait until observer data has been accumulated across every viewport size before trusting the
auto-criticality decision.

| Method | Condition | Use case |
| --- | --- | --- |
| `pageHasObserverData` | Any bucket has UIDs | Quick warm-up: inline as soon as first observer reports |
| `pageHasCompleteObserverData` | All configured buckets have UIDs | Conservative: wait for full cross-viewport coverage |

Both methods delegate to `AboveFoldCacheService` and never touch the database directly.

### `isElementAboveFold(int $elementUid, int $pageUid): bool`

Element-level check used by image ViewHelpers. Delegates to `CriticalDetectionService::isCritical()`
which resolves through three layers: DB `force_critical` flag → observer cache data →
position/sorting heuristic.

---

## Development

### Linting

```bash
composer lint:check     # Run all linters
composer lint:fix       # Fix auto-fixable issues
```

### Testing

```bash
composer test           # Run all tests
composer test:unit      # Run unit tests only
```

---

## License

GPL-2.0-or-later — see [LICENSE](../../LICENSE) for details.
