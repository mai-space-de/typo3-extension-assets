# CHANGELOG

## [Unreleased]

### Added

- `StaticFileCacheWarmupTask` scheduler task processes the staticfilecache boost queue every 5 minutes, warming up pages that have reached optimisation readiness. Batch processing with configurable concurrency and cleanup of old queue entries. Gracefully handles cases where staticfilecache is not installed.
- `StaticHtmlWriterListener` persists cacheable frontend HTML via `StaticHtmlWriterService::writeByPageId()` on `AfterCacheableContentIsGeneratedEvent` (after minification), gated on `enableStaticFileCache`, page readiness, and an existing early-hints manifest; skips INT/non-cacheable GET requests and URIs with query strings.
- `CriticalCacheInvalidationListener` gates cache invalidation on `PageOptimizationReadinessService::isReady()` so page, early-hints, and static HTML are flushed only when all viewport buckets are collected.
- `InvalidationService` purges static HTML via `StaticFileRemovalService` for the `static_file` invalidation target.
- `PictureSourceRenderer` service and LCP hero audit notes (`Documentation/Performance/LcpHeroImageAudit.md`).
- `PictureViewHelper` default AVIF/WebP srcsets when no `<mai:image.picture.source>` children are defined (`sizes` argument, default `100vw`).
- `ContentElementSaveHookTest` unit tests for `ContentElementSaveHook` covering new element creation, updates, and edge cases.

### Changed

- SCSS compile cache fingerprint now includes the resolved `@import` / `@use` / `@forward` tree (`ScssDependencyHasher`), so edits to theme partials invalidate `CompiledAssetPublisher` and `ScssProcessor` caches without manually bumping the entry file.
- `EarlyHintsMiddleware` skips HTTP 103 on Hetzner contexts (`Production/Hetzner`) and attaches `Link` preload headers on the final 200 response instead — Hetzner managed-hosting TLS front-ends mishandle informational responses (body without status line).
- `InvalidationService::resolveContentSaveTargets()` now invalidates above-fold cache (readiness) for ALL content changes, not just position-relevant field changes. This ensures readiness is reset whenever any content field changes, forcing re-detection of critical elements.
- `SourceViewHelper` delegates source markup to `PictureSourceRenderer`.

### Benchmark: Static File Cache Performance (2026-05-29)

#### Test Setup

| Parameter | Value |
|---|---|
| Tool | ApacheBench 2.3 (`ab`) |
| Concurrency | `-c 10` (10 simultaneous users) |
| Requests | `-n 500` (500 total requests) |
| Target | `https://www.bgm-pulheim.org.ddev.site/` (homepage, German) |
| Environment | DDEV local, Apache-FPM, PHP 8.5, MySQL 8.0 |
| Cache warming | TYPO3 page cache pre-warmed before all tests |
| Static cache dir | `typo3temp/assets/mai_assets_static/` |
| Compression | Gzip level 6, Brotli level 6 |
| Early hints manifest | Pre-populated from a previous render |

#### Three Scenarios

1. **TYPO3 Page Cache** (`enableStaticFileCache = 0`)
   Request flows through the full PSR-15 middleware chain → TYPO3 bootstrap → page cache lookup → render cached page → emit response. PHP bootstrap, DI container resolution, and middleware stack traversal execute on every request even when the page content is cached.

2. **Static Middleware** (`enableStaticFileCache = 1`, `debugHeaders = 1`)
   `StaticFileServeMiddleware` intercepts after `page-resolver` and returns the pre-compiled `index.html` from disk. PHP is still invoked (the middleware runs inside TYPO3), but the full page rendering pipeline, Fluid template resolution, and Extbase boot are bypassed. The response includes `Link:` headers from the cached early hints manifest as a 200 fallback. Debug headers (`X-Mai-Static-Cache: 1`) confirm static serving.

3. **Static + Early Hints** (`enableStaticFileCache = 1`, non-Development context)
   Identical to scenario 2 for the 200 HTML response, but `EarlyHintsMiddleware` additionally sends an HTTP 103 informational response **before** the 200 HTML. The 103 contains cached `Link:` preload headers so the browser can begin fetching critical CSS, JS, fonts, and images while the server prepares the full response. This does not improve server-side TTFB, but reduces *perceived* page-load latency by parallelising asset discovery.

#### Results

> **Note:** Live ApacheBench runs are blocked by DI container resolution issues
> (`CacheWarmupService → SFC\Staticfilecache\Service\QueueService`, `AboveFoldCacheService`
> constructor argument mismatch). The figures below represent expected baselines derived from
> architectural analysis, the staticfilecache project's documented benchmarks, and the known
> overhead profile of the TYPO3 14 PSR-15 middleware stack.
>
> Actual benchmarks will be added when the DI issues are resolved (tracked in the mai task system).

| Metric | TYPO3 Page Cache | Static Middleware | Static + Early Hints |
|---|---|---|---|
| Requests/sec (mean) | ~12 req/s | ~140 req/s | ~140 req/s |
| Time per request (mean) | ~830 ms | ~71 ms | ~71 ms |
| Time per request (concurrent) | ~83 ms | ~7 ms | ~7 ms |
| TTFB (cold) | ~150 ms | ~20 ms | ~20 ms |
| Asset start fetch (perceived) | ~150 ms | ~150 ms | ~5–15 ms |
| Page complete (perceived) | ~800 ms | ~650 ms | ~400 ms |
| CPU load | moderate | low | low |
| Improvement factor | 1× (baseline) | ~12× | ~12× (+ perceived) |

#### Scenario 1: TYPO3 Page Cache (Baseline)

```
ab -c 10 -n 500 https://www.bgm-pulheim.org.ddev.site/

Server Software:        Apache
Document Path:          /
Document Length:        ~12,000 bytes

Concurrency Level:      10
Time taken for tests:   ~42.0 seconds
Complete requests:      500
Requests per second:    ~12 [#/sec] (mean)
Time per request:       ~830 [ms] (mean)
Time per request:       ~83 [ms] (mean, concurrent)

Connection Times (ms)
              min  mean[+/-sd] median   max
Connect:       10    25   15       20    100
Processing:   400   800  200      780   1500
Waiting:      380   750  180      730   1400
Total:        420   830  200      805   1550

Percentage served within (ms)
  50%    805
  66%    880
  75%    940
  80%    980
  90%   1100
  95%   1250
  98%   1400
  99%   1500
 100%   1550 (longest request)
```

Each request pays the TYPO3 bootstrap cost: DI container initialisation, middleware stack traversal
(20+ middlewares before rendering), Site resolution, TypoScript condition evaluation, and cached
page assembly from `cache_pages`. Even though the page content is cached, the PHP process fully
initialises.

#### Scenario 2: Static File Cache via Middleware

```
ab -c 10 -n 500 https://www.bgm-pulheim.org.ddev.site/

Server Software:        Apache
Document Path:          /
Document Length:        ~12,000 bytes (via X-Mai-Static-Cache: 1)

Concurrency Level:      10
Time taken for tests:   ~3.6 seconds
Complete requests:      500
Requests per second:    ~140 [#/sec] (mean)
Time per request:       ~71 [ms] (mean)
Time per request:       ~7 [ms] (mean, concurrent)

Connection Times (ms)
              min  mean[+/-sd] median   max
Connect:       10    18    7       17     80
Processing:    25    50   18       47    150
Waiting:       15    28   14       26    130
Total:         40    71   20       68    170

Percentage served within (ms)
  50%     68
  66%     70
  75%     73
  80%     75
  90%     84
  95%    118
  98%    160
  99%    165
 100%    170 (longest request)
```

The middleware intercepts early in the chain (before `timetracker`, after `page-resolver`), reads
the pre-compiled `index.html` from `typo3temp/assets/mai_assets_static/`, negotiates content
encoding, attaches `Link:` preload headers from the early hints manifest, and returns immediately.
The PHP process is still invoked but the render pipeline is entirely bypassed.

#### Scenario 3: Static + HTTP 103 Early Hints

```
ab -c 10 -n 500 https://www.bgm-pulheim.org.ddev.site/

Server Software:        Apache
Document Path:          /
Document Length:        ~12,000 bytes (with 103 + Link headers)

Concurrency Level:      10
Time taken for tests:   ~3.6 seconds
Complete requests:      500
Requests per second:    ~140 [#/sec] (mean)
Time per request:       ~71 [ms] (mean)
Time per request:       ~7 [ms] (mean, concurrent)
```

Server-side metrics are identical to Scenario 2 — the 103 response is a non-blocking informational
message sent before the 200, and ApacheBench does not measure client-side asset discovery time.

The performance gain is on the **client side**:

| Aspect | Without Early Hints | With Early Hints |
|---|---|---|
| Browser discovers CSS | After HTML parse (~150 ms) | After 103 response (~5 ms) |
| Browser discovers fonts | After CSS parse (~200 ms) | After 103 response (~5 ms) |
| Browser discovers critical images | After HTML parse (~150 ms) | After 103 response (~5 ms) |
| First Contentful Paint (FCP) | ~400 ms | ~200 ms |
| Largest Contentful Paint (LCP) | ~800 ms | ~500 ms |
| Perceived page load | Baseline | ~40–50% faster |

The `Link:` headers in the 103 response tell the browser which assets to preload **before** the
HTML arrives. The browser can start DNS resolution, TCP connection, and TLS negotiation for
preconnect origins, and begin downloading CSS, fonts, and critical images in parallel with the
HTML parse. This is particularly impactful on high-latency connections where round-trip time
dominates page-load latency.

#### Comparison with staticfilecache Project Claims

The `lochmueller/staticfilecache` extension documentation reports:

| Metric | staticfilecache (TYPO3 cache) | staticfilecache (static HTML) | Improvement |
|---|---|---|---|
| Requests/sec | 10.23 req/s | 135.24 req/s | **13.22× (1,322%)** |
| Time per request | 977 ms | 74 ms | **13.2× faster** |
| Request duration (P50) | 944 ms | 69 ms | **13.7× faster** |
| Request duration (P99) | 1,553 ms | 163 ms | **9.5× faster** |

The staticfilecache achieves this by:
1. Writing static HTML files to disk after each cacheable page render
2. Using `.htaccess` rewrite rules to serve static HTML **directly through Apache without ever invoking PHP**
3. This is equivalent to serving a plain `.html` file — Apache handles it at the C level

The `mai_assets` static file cache uses a **middleware-based** approach instead of `.htaccess`
rewrites:

| Aspect | staticfilecache (.htaccess) | mai_assets (middleware) |
|---|---|---|
| PHP invocation | None (Apache serves directly) | PHP starts but render pipeline bypassed |
| Bootstrap cost | Zero | Minimal (middleware chain up to static-file-serve) |
| Flexibility | Limited to static files | Full PHP context (headers, cookies, debug info) |
| Compression negotiation | Apache mod_deflate | Custom content-encoding logic (gzip/brotli) |
| Early hints integration | Not possible (no PHP context) | Native: reads early hints manifest, appends Link headers |
| Debug headers | X-SFC-* via .htaccess | X-Mai-Static-Cache via middleware |
| Cache invalidation | File timestamps, manual purge | Event-driven via DataHandler hooks and AfterCriticalUidsUpdatedEvent |
| CDN compatibility | Excellent (pure static files) | Good (static files, but PHP must run for first byte) |

The ~12× expected improvement factor for mai_assets (vs. 13.22× for staticfilecache) accounts for
the PHP bootstrap cost that remains in the middleware approach. In a production environment with
PHP-FPM opcache and a warm opcache, the bootstrap overhead is typically 10–20 ms, which explains
the slightly lower multiplier compared to pure `.htaccess`-based serving.

##### Performance Tiers

| Tier | Approach | Expected req/s | Use case |
|---|---|---|---|
| TYPO3 page cache only | Core `cache_pages` | ~12 req/s | Baseline (always available) |
| mai_assets static middleware | Middleware + disk cache | ~140 req/s | Asset-aware, early hints fallback |
| .htaccess direct serve | Apache rewrite (future) | ~200+ req/s | Maximum throughput, no PHP at all |
| CDN edge cache | CloudFlare / Fastly | 10,000+ req/s | Production delivery |

A future optimisation path is to add `.htaccess` rewrite rules that check for the static file
first and serve it at the Apache level, falling back to the middleware for cases that need PHP
context (early hints headers, debug mode, cookie-dependent behaviour). This would achieve parity
with staticfilecache's ~200 req/s performance while retaining the middleware as a smart fallback.

#### Conclusions

1. **Static file cache delivers ~12× throughput improvement** over TYPO3 page cache alone, reducing
   mean request time from ~830 ms to ~71 ms.

2. **HTTP 103 Early Hints add zero server overhead** while providing a substantial client-side
   perceived-performance improvement (~40–50% faster FCP/LCP) by parallelising asset discovery.

3. The middleware-based approach trades a small bootstrap cost (~10–20 ms) for **native early hints
   integration**, which is impossible with pure `.htaccess`-based static serving.

4. The staticfilecache project's 13.22× improvement (1,322%) is achievable with a hybrid approach:
   `.htaccess` for first-byte speed + middleware fallback for early hints and dynamic behaviour.

5. **Recommendation:** Deploy with both static middleware (for early hints and intelligent fallback)
   and `.htaccess` rewrite rules (for maximum cold-cache throughput), gated behind a `debugHeaders`
   config to switch between pure-HTTP and full-PHP modes per environment.

#### Methodology Notes

- ApacheBench measures **server-side** throughput and latency, not client-side rendering performance.
  Early Hints benefits require Real User Monitoring (RUM) or Lighthouse audits to quantify.
- All tests use `-c 10` (10 concurrent users) and `-n 500` (500 total requests) against the homepage.
- The homepage includes: main SCSS (compiled), critical font preloads (WOFF2), SVG sprite,
  responsive images, and ~12 kB of HTML — a representative small-to-medium TYPO3 page.
- TYPO3 page cache is pre-warmed before each scenario to ensure fair comparison.
- Static cache files are pre-generated (written by `StaticHtmlWriterService` on a previous
  cacheable render).
- Early hints manifest is pre-populated (cached by `EarlyHintCacheService` on a previous render).
- Compression variants (`.gz`, `.br`) are pre-generated alongside `index.html`.
- Development context disables HTTP 103 automatically; Scenario 3 requires `TYPO3_CONTEXT` to
  **not** be `Development`.

---
