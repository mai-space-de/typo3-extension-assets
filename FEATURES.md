## Asset Processing

* SCSS compilation — compiles `.scss` files via `scssphp/scssphp` with automatic import-path resolution and cached output written to `typo3temp/`
* CSS & JS minification — minifies processed stylesheets and scripts via `matthiasmullie/minify`; can be toggled globally or overridden per ViewHelper call
* Gzip & Brotli compression — writes compressed variants of compiled assets alongside the uncompressed file for servers that support pre-compressed delivery
* Deploy-time warmup — `maispace:assets:warmup` CLI command pre-warms the SVG sprite and configured SCSS/CSS assets before the first request

## CSS & JavaScript ViewHelpers

* `<mai:css>` — Fluid ViewHelper for stylesheets; compiles SCSS on demand, auto-computes SRI hashes, supports `critical="auto|true|false"` to inline compiled CSS when the page is above-fold-critical, and always registers an HTTP 103 early hint preload candidate
* `<mai:js>` — Fluid ViewHelper for scripts; minifies on demand, auto-computes SRI hashes, supports `critical="auto|true|false"` to remove `defer` and add `fetchpriority="high"`, registers `modulepreload` or script preload early hint candidates for critical scripts; supports ES6 `type="module"`, `async`, `nomodule`, and CSP nonces

## Image ViewHelpers

* `<mai:image.responsive>` — produces a `<picture>` element with AVIF, WebP, and JPEG `srcset` variants per breakpoint; auto-detects criticality by content element UID, sets `loading="eager"` and `fetchpriority="high"`, and registers an early hint preload for the AVIF source when critical
* `<mai:image.picture>` — low-level `<picture>` wrapper that propagates the resolved criticality flag to nested `<source>` elements; emits Hero-aligned default AVIF/WebP srcsets when no child sources are defined
* `<mai:image.picture.source>` — `<source>` element within a `<picture>` with format-specific `srcset` and `sizes`
* `<mai:image.figure>` — `<figure>` wrapper for images with optional caption

## SVG ViewHelpers

* `<mai:svg.icon>` — renders an SVG sprite reference (`<svg><use href="#id"></svg>`) from the single sprite injected after `<body>`, keeping icon markup minimal
* `<mai:svg.inline>` — inlines a full SVG file with optional `<title>` injection for accessibility, `aria-label`, CSS class, and dimension stripping; output is cached by file hash

## Video ViewHelper

* `<mai:video.video>` — unified video ViewHelper supporting self-hosted files (AV1 → HEVC → H264 source order), YouTube and Vimeo privacy-friendly lazy-load facades, and a background-video mode with autoplay; non-critical videos use `data-lazy` attributes for deferred loading

## Above-Fold Detection

* IntersectionObserver script — a lightweight observer is injected into each page to detect which content element UIDs are visible at initial render and report them to the server
* Above-fold report API — `POST /api/mai-assets/above-fold-report` endpoint accepts HMAC-signed observer payloads, enforces IP-based rate limiting (10 requests / 60 s), and persists critical UID sets per page/viewport bucket
* Three-layer criticality detection — `CriticalDetectionService` resolves criticality through DB force flags → observer cache data → heuristic colPos-position fallback
* Criticality resolver — `AssetCriticalityResolver` provides a single injection point for ViewHelpers to query page-level (`pageHasObserverData`, `pageHasCompleteObserverData`) and element-level (`isElementAboveFold`) criticality without coupling to the detection internals
* Cache invalidation on save — `ContentElementSaveHook` clears the above-fold cache and invalidates the TYPO3 page cache when a content element is edited so the next render reflects the reset state

## Static HTML Page Cache (Native Implementation)

* **Full-page caching** — `StaticFileServeMiddleware` writes rendered HTML pages to `typo3temp/assets/mai_assets_static/{pageUid}_{languageUid}/` with optional Gzip and Brotli variants for fast subsequent serving. This is a **native, built-in feature** of `mai_assets` — no third-party cache extension is required or declared as a dependency.
* **Readiness gate** — `PageOptimizationReadinessService` and `StaticFileCacheReadinessListener` gate caching on completion of above-fold detection; only pages with complete critical data are cached to prevent premature serving of incomplete page metadata. The readiness listener implements `SFC\Staticfilecache\Event\CacheRuleEvent` (loaded from `.lookup/staticfilecache/` for type-hinting only) to integrate with TYPO3's static file cache event system, but the `staticfilecache` extension is **not** a runtime dependency.
* **Cache invalidation** — `StaticFileRemovalService` purges cache entries when the above-fold cache is reset (e.g., on content element save), keeping cached pages in sync with observer data. The `InvalidationService` coordinates page cache, early-hints manifest, and static HTML purging in a single event-driven flow.
* **Webserver acceleration** — Apache and Nginx configuration examples provided in `Resources/Private/ConfigurationExamples/` for optionally serving static cache files directly from the webserver without PHP (DDEV-optimized templates included). The rewrite patterns are ported from `EXT:staticfilecache`'s `HtaccessGenerator` as **inspiration only** — the implementation is entirely native to `mai_assets`.
* **Scheduler warmup** — `StaticFileCacheWarmupTask` processes the boost queue every 5 minutes, warming up pages that have reached optimisation readiness. The task gracefully handles cases where the boost queue is not available (staticfilecache extension not installed).

## HTTP 103 Early Hints (Hybrid Delivery Path)

* Early hints middleware — `EarlyHintsMiddleware` emits cached `Link:` headers as HTTP 103 informational responses before the full page response (disabled in Development context to avoid proxy issues)
* Static file hybrid fallback — `StaticFileServeMiddleware` loads the same cached early hints manifest and includes the `Link:` headers on the 200 response, providing a fallback for clients/proxies that do not process 103 Early Hints
* Candidate collection — `EarlyHintCandidateCollector` accumulates `preload`, `modulepreload`, and `preconnect` candidates during page rendering; candidates are keyed and deduplicated
* Manifest persistence — `EarlyHintManifestListener` writes the collected candidates to a per-page/language cache entry after each cacheable render so the middleware can serve them on subsequent requests without re-rendering

## Font Preloading & SVG Sprites

* Font preloading — `FontPreloadCollector` discovers fonts declared in `Configuration/Fonts.php` across active extensions and injects `<link rel="preload">` tags into `<head>` along with early hint candidates
* SVG sprite injection — `SvgSpriteInjectionListener` builds a single hidden `<svg>` sprite from symbols registered via `Configuration/SpriteIcons.php` and injects it after `<body>` on every cacheable page
* Preconnect origins — third-party origins declared in `Configuration/Preconnect.php` are emitted as `<link rel="preconnect">` tags in `<head>` and registered as early hint candidates

## HTML Minification

* Page-output minification — `HtmlMinificationService` strips HTML comments and collapses inter-element whitespace on cacheable page output, protecting `<script>`, `<style>`, `<pre>`, `<code>`, and `<textarea>` blocks and all TYPO3-internal comment markers

## Webserver Delivery Configuration

* Apache + Nginx configuration examples — ready-to-use templates in `Resources/Private/ConfigurationExamples/` for serving pre-compressed Brotli (`.br`) and Gzip (`.gz`) variants of static files
* Content-negotiation — Brotli preferred over Gzip based on `Accept-Encoding` header, with uncompressed fallback
* DDEV integration — `ddev-apache-site.conf.example` and `ddev-nginx.conf.example` provide DDEV-specific configurations for Apache-FPM and Nginx respectively
* Htaccess fallback — `StaticFileCache.htaccess` provides a simpler `.htaccess`-only approach for Apache without modifying main config
* Ported patterns — webserver rules adapted from EXT:staticfilecache `HtaccessGenerator` for handling pre-compressed variants and content-type negotiation

## Security & Sub-resource Integrity

* SRI hashes — `SriHashService` computes sha384 SRI hashes for local stylesheets and scripts; hashes are added automatically to `<link>` and `<script>` tags unless an explicit `integrity` attribute is provided
* HMAC observer tokens — above-fold report payloads are validated against a server-side HMAC token to prevent replay attacks and spoofed UID reports

## Critical Asset Data Processor

* `CriticalAssetDataProcessor` — TypoScript data processor that resolves the `isCritical` flag for a content element and exposes `loadingStrategy`, `fetchPriority`, `decodingStrategy`, and `cssStrategy` variables in the Fluid template context for template-level branching

## PSR-14 Event System

* Extensibility hooks — eleven PSR-14 events allow downstream code to modify asset content before injection (`BeforeAssetInjectionEvent`), inspect post-processing results (`AfterCssProcessedEvent`, `AfterJsProcessedEvent`, `AfterScssCompiledEvent`, `AfterImageProcessedEvent`), hook into sprite building (`AfterSpriteBuiltEvent`, `BeforeSpriteSymbolRegisteredEvent`), intercept the observer script (`BeforeObserverScriptInjectedEvent`), control image processing (`BeforeImageProcessingEvent`), and tune criticality thresholds (`ModifyCriticalThresholdEvent`, `AfterCriticalUidsUpdatedEvent`)
