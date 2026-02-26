# maispace/assets — TYPO3 Asset ViewHelpers

A TYPO3 extension that provides Fluid ViewHelpers for CSS, JavaScript, SCSS, images, SVG sprites, and web font preloading — all from Fluid templates, with performance-first defaults.

**Requires:** TYPO3 12.4 LTS or 13.x LTS · PHP 8.1+

---

## Features at a glance

| Feature | ViewHelper / API |
|---|---|
| CSS from file or inline | `<ma:css>` |
| JavaScript from file or inline | `<ma:js>` |
| SCSS compiled server-side (no Node.js) | `<ma:scss>` |
| Responsive `<img>` with lazy load + preload | `<ma:image>` |
| Responsive `<picture>` with per-breakpoint sources | `<ma:picture>` + `<ma:picture.source>` |
| Semantic `<figure>/<figcaption>` wrapper | `<ma:figure>` |
| SVG sprite served from a cacheable URL | `<ma:svgSprite>` + `Configuration/SpriteIcons.php` |
| Web font `<link rel="preload">` in `<head>` | `Configuration/Fonts.php` |
| Multi-site scoping for sprites and fonts | `'sites'` key in config files |
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

Include assets inline or from a file. Assets are minified, cached in `typo3temp/`, and registered with TYPO3's AssetCollector.

```html
<!-- CSS from file (deferred by default via media="print" swap) -->
<ma:css src="EXT:theme/Resources/Public/Css/app.css" />

<!-- Critical CSS inlined in <head> -->
<ma:css identifier="critical" priority="true" inline="true">
    body { margin: 0; font-family: sans-serif; }
</ma:css>

<!-- JS (defer="true" by default) -->
<ma:js src="EXT:theme/Resources/Public/JavaScript/app.js" />

<!-- ES module -->
<ma:js src="EXT:theme/Resources/Public/JavaScript/app.js" type="module" />
```

---

## SCSS

Compile SCSS to CSS server-side using [scssphp](https://scssphp.github.io/scssphp/) — no Node.js required.

```html
<ma:scss src="EXT:theme/Resources/Private/Scss/main.scss" />

<!-- Additional @import paths -->
<ma:scss src="EXT:theme/Resources/Private/Scss/main.scss"
         importPaths="EXT:theme/Resources/Private/Scss/Partials" />
```

Cache is automatically invalidated when the source file changes (`filemtime`).

---

## Images

Process images via TYPO3's native ImageService (supports WebP conversion, cropping, etc). Accept FAL UIDs, File/FileReference objects, or EXT: paths.

### `<ma:image>` — single `<img>`

```html
<!-- From a sys_file_reference UID -->
<ma:image image="{file.uid}" alt="{file.alternative}" width="800" />

<!-- Hero image: preloaded, high priority, no lazy -->
<ma:image image="{hero}" alt="{heroAlt}" width="1920"
          lazyloading="false" preload="true" fetchPriority="high" />

<!-- Lazy load with a JS-hook class (e.g. for lazysizes) -->
<ma:image image="{img}" alt="{alt}" width="427c" height="240"
          lazyloadWithClass="lazyload" />
```

Width/height notation: `800` (exact) · `800c` (centre crop) · `800m` (max, proportional)

### `<ma:picture>` + `<ma:picture.source>` — responsive `<picture>`

Sources are configured inline in the template — no central YAML file needed.

```html
<ma:picture image="{imageRef}" alt="{alt}" width="1200" lazyloadWithClass="lazyload">
    <ma:picture.source media="(min-width: 980px)" width="1200" height="675" />
    <ma:picture.source media="(min-width: 768px)" width="800" height="450" />
    <ma:picture.source media="(max-width: 767px)" width="400" height="225" />
</ma:picture>
```

Each `<ma:picture.source>` processes the image independently to the specified dimensions. Override the image for a specific breakpoint with the `image` argument.

### `<ma:figure>` — semantic figure wrapper

```html
<ma:figure caption="{file.description}" class="article-figure">
    <ma:picture image="{file}" alt="{file.alternative}" width="1200">
        <ma:picture.source media="(min-width: 768px)" width="1200" />
        <ma:picture.source media="(max-width: 767px)" width="600" />
    </ma:picture>
</ma:figure>
```

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
<ma:svgSprite use="icon-arrow" width="24" height="24" class="icon" />

<!-- Meaningful icon -->
<ma:svgSprite use="icon-close" aria-label="Close dialog" width="20" height="20" />
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
        lazyloadWithClass =  # e.g. "lazyload" for lazysizes
    }
    fonts {
        preload = 1  # 0 to suppress all font preload tags globally
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

Example listeners with full documentation are in `Classes/EventListener/` (inactive by default). Copy the relevant `Services.yaml` block to your site package to activate one.

---

## Registering Fonts and Icons from Your Extension

Both registries use the same auto-discovery pattern — no `ext_localconf.php` registration needed:

| File | Purpose |
|---|---|
| `EXT:my_ext/Configuration/SpriteIcons.php` | Register SVG symbols for the sprite |
| `EXT:my_ext/Configuration/Fonts.php` | Register fonts for `<link rel="preload">` |

The registries scan all loaded TYPO3 extensions for these files on first use. Later-loaded extensions win on key conflicts, so site packages can override vendor icons/fonts.

---

## License

GPL-2.0-or-later
