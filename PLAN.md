# PLAN.md — mai_assets Extension

Authoritative forward-looking development plan. Written for an AI Agent to execute.
Supersedes all previous planning documents (FEATURES.md, MISSING_FEATURES.md, FeaturePlan.md,
HMAC.md, EarlyHints.md — all deleted).

---

## 1. Vision

Developers write asset declarations once. The extension automates every delivery decision.

```fluid
{* Developer declares intent — nothing more *}
<mai:css src="EXT:theme/Resources/Public/Css/main.scss" identifier="theme-main" />
<mai:js  src="EXT:theme/Resources/Public/JavaScript/app.js" identifier="theme-app" />
<mai:image image="{image}" alt="{alt}" elementUid="{data.uid}" />
```

The extension decides at render time — from observer data accumulated per page UID — whether
each asset is critical, whether it should be inlined, and whether it deserves an HTTP 103 Early
Hint on the next request. Developers who need to override the system can pass an explicit
`critical` parameter, but they should rarely need to.

### What "automatic" means per asset type

| Asset | Auto-critical signal | Critical delivery |
|---|---|---|
| CSS / SCSS | Page has any observer data for current PID | Inline as `<style>` |
| JS | Page has any observer data for current PID | Early hint as `modulepreload`; no defer |
| Image | Content element UID is in observer list for current PID | `fetchpriority="high"`, `loading="eager"`, early hint preload |
| Fonts | Declared in `Configuration/Fonts.php` | Always preloaded + early hinted (no observer needed) |

First-request behaviour: no observer data exists yet → CSS/JS are served as external files
(no inlining). After real user visits accumulate observer reports, subsequent requests receive
inlined critical CSS and prioritised JS. This is an intentional "warm-up" model.

---

## 2. Current State

The following layers are **complete and must not be re-implemented**:

| Layer | Key classes |
|---|---|
| Processing | `MinificationProcessor`, `ScssProcessor`, `CompressionProcessor`, `CompiledAssetPublisher` |
| Observer pipeline | `AboveFoldObserverListener`, `AboveFoldReportMiddleware`, `AboveFoldCacheService`, `AboveFoldTokenService` |
| Early hints pipeline | `EarlyHintCandidateCollector`, `EarlyHintCacheService`, `EarlyHintManifestListener`, `EarlyHintsMiddleware` |
| Critical detection | `CriticalDetectionService` (DB flags → observer cache → heuristic fallback) |
| Collectors | `SvgSpriteCollector`, `FontPreloadCollector`, `ExtensionConfigurationDiscovery` |
| Asset ViewHelpers | `CssViewHelper`, `JsViewHelper` (registration, SRI, minification — lacking auto-criticality) |
| Image ViewHelpers | `ResponsiveImageViewHelper`, `PictureViewHelper`, `Picture/SourceViewHelper`, `FigureViewHelper` (explicit `isCritical` bool — needs automation) |
| SVG ViewHelpers | `IconViewHelper`, `InlineViewHelper` |
| Event system | All PSR-14 events + listeners |
| Exceptions | Full typed hierarchy under `Classes/Exception/` |
| HTML minification | `HtmlMinificationService`, `HtmlMinificationListener` |
| Security | `AboveFoldTokenService` (HMAC), rate-limiting in `AboveFoldReportMiddleware` |
| CLI | `WarmupCommand` |
| Config | `ExtensionConfiguration`, `ExtensionConfigurationDiscovery`, `Fonts.php` + `SpriteIcons.php` contracts |

---

## 3. Gap Analysis — Where the Vision Deviates

### 3.1 `CriticalStyleViewHelper` — exposes what should be internal

**Problem**: requires the template author to pass `isCritical="{isCritical}"` explicitly. The
developer must wire up the detection result themselves, which couples templates to the
criticality mechanism.

**Solution**: delete this ViewHelper. `CssViewHelper` absorbs its inline-CSS delivery path,
driven by `critical="auto"` (the new default).

### 3.2 `PreloadFontViewHelper` — requires `isCritical` parameter

**Problem**: developer must explicitly write `<mai:asset.preloadFont path="..." isCritical="1" />`.
Fonts declared in `Configuration/Fonts.php` are already auto-discovered by `FontPreloadCollector`
and always emitted as preload links + early hint candidates. The ViewHelper adds nothing.

**Solution**: delete this ViewHelper. All font preloading is configuration-driven via
`Configuration/Fonts.php`.

### 3.3 `HintViewHelper` — preload hints should be automatic

**Problem**: developer must write `<mai:asset.hint rel="preload" as="style" href="..." />`
for every asset they want hinted. CSS/JS/image ViewHelpers should register early hint
candidates themselves when they determine an asset is critical.

**Solution**: delete this ViewHelper. Its `preload`/`prefetch`/`modulepreload` cases are
replaced by automatic early hint registration inside each asset ViewHelper. Retain
`preconnect` and `dns-prefetch` support through a new `Configuration/Preconnect.php`
discovery file (see §4.4).

### 3.4 `CssViewHelper` — no criticality awareness

**Problem**: the ViewHelper always registers an external stylesheet. It has an `inline` flag
but no awareness of observer data. It does not register early hint candidates.

**Solution**: add `critical` parameter (default `'auto'`). When `auto`, check observer data
for the current PID. When critical: inline the compiled CSS. In all cases, register an early
hint candidate for the compiled public path.

### 3.5 `JsViewHelper` — no criticality awareness

**Problem**: always defers; never registers early hint candidates.

**Solution**: add `critical` parameter (default `'auto'`). When critical: remove `defer`,
register `modulepreload` early hint.

### 3.6 `ResponsiveImageViewHelper` / `PictureViewHelper` — explicit `isCritical` bool

**Problem**: developer passes `isCritical="{isCritical}"` — same manual wiring issue as CSS.

**Solution**: replace `isCritical: bool` with `critical: string` (default `'auto'`). When
`auto`, delegate to `CriticalDetectionService` using the `elementUid` argument. Critical
images auto-register an early hint preload candidate.

---

## 4. Architecture

### 4.1 `AssetCriticalityResolver` (new service)

**File**: `Classes/Service/AssetCriticalityResolver.php`

Single, injected service that ViewHelpers call to resolve criticality without knowing how
the decision is made. Keeps detection logic out of ViewHelpers.

```php
final class AssetCriticalityResolver
{
    public function __construct(
        private readonly AboveFoldCacheService $aboveFoldCacheService,
        private readonly CriticalDetectionService $criticalDetectionService,
    ) {}

    /**
     * Page-level: any observer data exists for this PID across any bucket.
     * Used by CSS and JS ViewHelpers.
     */
    public function pageHasObserverData(int $pageUid): bool
    {
        return $this->aboveFoldCacheService->getAllCriticalUids($pageUid) !== [];
    }

    /**
     * Element-level: this content element UID is in the above-fold set.
     * Used by image ViewHelpers.
     */
    public function isElementAboveFold(int $elementUid, int $pageUid): bool
    {
        return $this->criticalDetectionService->isCritical($pageUid, $elementUid);
    }
}
```

`CriticalDetectionService` already handles the DB-flag → observer-cache → heuristic fallback
chain. `AssetCriticalityResolver` is a thin coordinator, not a replacement.

### 4.2 Updated `CssViewHelper`

New `critical` argument: `string`, allowed values `'auto'|'true'|'false'`, default `'auto'`.

Resolution logic inside `render()`:

```
$pageUid = (int) $this->renderingContext->getRequest()
    ->getAttribute('routing')?->getPageId() ?? 0;

$isCritical = match($critical) {
    'true'  => true,
    'false' => false,
    default => $pageUid > 0 && $this->criticalityResolver->pageHasObserverData($pageUid),
};
```

**When critical** (`$isCritical === true`):
1. Read file content
2. Compile SCSS if `.scss` extension (via `ScssProcessor`)
3. Minify (via `MinificationProcessor`) if configured
4. Fire `BeforeAssetInjectionEvent`
5. Return `<style>{$content}</style>`
6. Also register compiled file path as early hint (`rel=preload, as=style`) so the browser
   can preload the external file on the next uncached request

**When not critical**:
1. Publish via `CompiledAssetPublisher` (compile + minify + cache)
2. Compute SRI via `SriHashService`
3. Register via `AssetCollector::addStyleSheet()`
4. Register early hint candidate (`rel=preload, as=style`) — always, not just when critical

Removes the `inline` parameter (its only valid use case is now `critical="true"`). Existing
templates using `inline=true` must be migrated to `critical="true"`.

### 4.3 Updated `JsViewHelper`

New `critical` argument: `string`, allowed values `'auto'|'true'|'false'`, default `'auto'`.

Same page-UID resolution as CSS.

**When critical** (`$isCritical === true`):
- Do not add `defer` attribute
- Add `fetchpriority="high"` to the script tag attributes
- Register early hint candidate as `rel=modulepreload` (for `type=module` scripts) or
  `rel=preload, as=script` (for classic scripts)

**When not critical**:
- Keep `defer=true` default
- Do not register early hint candidate

The `async` parameter remains available as an explicit override in both modes.

### 4.4 Updated `ResponsiveImageViewHelper` and `PictureViewHelper`

Replace `isCritical: bool` with:
- `critical: string` — `'auto'|'true'|'false'`, default `'auto'`
- `elementUid: int` — optional; required for `critical="auto"` to work; defaults to `0`

```
$isCritical = match($critical) {
    'true'  => true,
    'false' => false,
    default => $elementUid > 0 && $pageUid > 0
               && $this->criticalityResolver->isElementAboveFold($elementUid, $pageUid),
};
```

When `critical="auto"` and `elementUid` is omitted or 0, default to **not critical** (safe
fallback; developer can always force `critical="true"`).

When critical:
1. `loading="eager"`, `fetchpriority="high"`, `decoding="sync"`
2. Register best-format (AVIF or WebP) preload link via `AssetCollector::addLink()`
3. Register early hint candidate (`rel=preload, as=image`)

`PictureViewHelper` propagates the resolved `$isCritical` bool down to `SourceViewHelper`
children via the Fluid rendering context variable bag (same channel used for source→picture
communication today) so that `<source>` elements can also set `fetchpriority`.

### 4.5 `Configuration/Preconnect.php` — replaces `HintViewHelper` for origins

Each extension or site package can ship this file to declare third-party preconnects:

```php
// EXT:my_theme/Configuration/Preconnect.php
return [
    'sites' => ['*'],
    'origins' => [
        ['href' => 'https://fonts.googleapis.com', 'crossorigin' => 'anonymous'],
        ['href' => 'https://cdn.example.com'],
    ],
];
```

`ExtensionConfigurationDiscovery` gains a `discoverPreconnects(): array` method.
`SvgSpriteInjectionListener` (which already runs on `AfterCacheableContentIsGeneratedEvent`)
emits the preconnect `<link>` tags into `<head>` alongside fonts.
`EarlyHintManifestListener` also picks them up as early hint candidates.

This makes third-party preconnects configuration-driven and cacheable, not template-driven.

### 4.6 Early hints automation — summary of wiring

After all changes above, the early hint pipeline is fully automatic:

| Source | Auto-registers early hint? |
|---|---|
| `CssViewHelper` (any mode) | Yes — preload for compiled stylesheet |
| `JsViewHelper` (critical only) | Yes — modulepreload or script preload |
| `ResponsiveImageViewHelper` (critical only) | Yes — image preload |
| `PictureViewHelper` (critical only) | Yes — image preload |
| `FontPreloadCollector` (via `Fonts.php`) | Yes — always font preload |
| `Configuration/Preconnect.php` | Yes — preconnect |

No template author needs to think about early hints.

### 4.7 TYPO3 caching behaviour — important constraints

TYPO3's full-page cache stores the rendered HTML output. The criticality decision happens at
render time, meaning:

- **Cached pages** deliver whichever criticality state was active when the page was last
  rendered. If observer data arrives after caching, the inlined CSS only appears after the
  cache is invalidated.
- When a content element is saved, `ContentElementSaveHook` does **not** flush a
  `maiAssetsAboveFold_{pageUid}` cache tag. Instead it calls
  `AboveFoldCacheService::clearCriticalUids()` and `AboveFoldCacheService::bumpResetTimestamp()`,
  then flushes the TYPO3 page cache for the affected page via `pageId_{pageUid}` so the next
  uncached render picks up the reset above-fold state.
- `AboveFoldCacheService::updateCriticalUids()` calls `AfterCriticalUidsUpdatedEvent`; any
  follow-up invalidation work here should target TYPO3 page-cache invalidation for the affected
  page/PID, not rely on a non-existent `maiAssetsAboveFold_{pageUid}` tag flush.
- The early hints manifest (`mai_assets_early_hints` cache) is keyed by `pageUid + language`
  and is updated by `EarlyHintManifestListener` on cacheable renders when the current
  `EarlyHintCandidateCollector` contains candidates. If a render produces no candidates,
  the previous manifest entry can persist until it is flushed by its cache tags.

---

## 5. Removals and Migrations

### 5.1 Delete `CriticalStyleViewHelper`

**File to delete**: `Classes/ViewHelpers/Asset/CriticalStyleViewHelper.php`

**Migration for templates** that use `<mai:asset.criticalStyle isCritical="{isCritical}" source="...">`:

```fluid
{* Before *}
<mai:asset.criticalStyle identifier="theme-main" source="EXT:theme/..." isCritical="{isCritical}" />

{* After *}
<mai:css identifier="theme-main" src="EXT:theme/..." critical="auto" />
{* If the template provides a content element UID context, also pass elementUid.
   For layout/global CSS that applies to the whole page, critical="auto" uses page-level
   observer data — no elementUid needed. *}
```

### 5.2 Delete `PreloadFontViewHelper`

**File to delete**: `Classes/ViewHelpers/Asset/PreloadFontViewHelper.php`

**Migration**: remove all `<mai:asset.preloadFont>` calls from templates.
Declare fonts in `EXT:my_theme/Configuration/Fonts.php` — they are auto-discovered,
auto-preloaded, and auto-early-hinted without any template code.

### 5.3 Delete `HintViewHelper`

**File to delete**: `Classes/ViewHelpers/Asset/HintViewHelper.php`

**Migration for preload hints**: none needed — `CssViewHelper`, `JsViewHelper`, and image
ViewHelpers now register preload hints automatically.

**Migration for preconnect/dns-prefetch**: move origin declarations to
`EXT:my_theme/Configuration/Preconnect.php` (see §4.5).

### 5.4 Remove `inline` parameter from `CssViewHelper`

Templates using `inline=true` must change to `critical="true"`. The semantics are identical:
compile, minify, emit as `<style>`. The parameter name is clearer about intent.

### 5.5 Update `FontPreloadService`

`FontPreloadService::registerCriticalFont()` was called only by the deleted
`PreloadFontViewHelper`. It can be deleted or kept as an internal helper called by
`FontPreloadCollector` — review during implementation.

---

## 6. New `CriticalCacheInvalidationListener`

**File**: `Classes/EventListener/CriticalCacheInvalidationListener.php`

Listens to `AfterCriticalUidsUpdatedEvent` (fired when observer data changes for a PID).
Flushes TYPO3 page cache for the affected `pageUid` so the next request produces a fresh
render with inlined critical CSS.

```php
#[AsEventListener(identifier: 'mai-assets/critical-cache-invalidation')]
final class CriticalCacheInvalidationListener
{
    public function __construct(private readonly CacheManager $cacheManager) {}

    public function __invoke(AfterCriticalUidsUpdatedEvent $event): void
    {
        $this->cacheManager->flushCachesByTag('pageId_' . $event->getPageUid());
    }
}
```

This closes the loop: observer reports → cache invalidated → next render inlines CSS → early
hints manifest updated → next-next request gets HTTP 103.

---

## 7. Implementation Order

Dependencies determine sequence. Each step can start only when its prerequisites are complete.

| # | Deliverable | Prerequisites | Notes |
|---|---|---|---|
| 1 | `AssetCriticalityResolver` service | none | Thin coordinator; pure DI |
| 2 | `CriticalCacheInvalidationListener` | none | Closes the observer→cache→render loop |
| 3 | Update `CssViewHelper`: add `critical` param, remove `inline` param, auto-register early hint | `AssetCriticalityResolver` | Absorbs `CriticalStyleViewHelper` responsibility |
| 4 | Update `JsViewHelper`: add `critical` param, auto-register early hint | `AssetCriticalityResolver` | |
| 5 | Update `ResponsiveImageViewHelper`: replace `isCritical: bool` with `critical: string` + `elementUid: int`, auto-register early hint | `AssetCriticalityResolver` | |
| 6 | Update `PictureViewHelper` + `SourceViewHelper`: same `critical` / `elementUid` changes | step 5 | Propagate resolved bool via rendering context |
| 7 | `Configuration/Preconnect.php` contract + `ExtensionConfigurationDiscovery::discoverPreconnects()` | none | |
| 8 | Emit preconnect `<link>` tags in `SvgSpriteInjectionListener` from discovered origins | step 7 | Also add as early hint candidates |
| 9 | Delete `CriticalStyleViewHelper` | step 3 | After confirming step 3 covers all use cases |
| 10 | Delete `PreloadFontViewHelper` | none | Fonts handled by `FontPreloadCollector` |
| 11 | Delete `HintViewHelper` | steps 3–8 | Preload + preconnect both covered |
| 12 | Review and delete `FontPreloadService` if only used by deleted ViewHelper | step 10 | |
| 13 | Update TypoScript settings tree to document `critical` thresholds + new `preconnect` config | steps 1–8 | |
| 14 | Migrate any internal template usage (`Resources/Private/`) from old to new ViewHelper API | steps 3–6 | |
| 15 | Unit tests for `AssetCriticalityResolver`, updated ViewHelpers, new listener | all above | ✅ `AssetCriticalityResolverTest` · ✅ `CssViewHelperTest` (7 tests: critical=true/false/auto) · ⬜ `JsViewHelperTest` · ⬜ `CriticalCacheInvalidationListenerTest` |

---

## 8. Architecture Constraints

These rules must be followed in all new and modified code:

1. **No static `GeneralUtility::makeInstance()` calls** in service classes — constructor DI only.
2. **`AssetCollector` over `PageRenderer`** for CSS/JS file registration (TYPO3 14 compatibility).
   `PageRenderer::addHeaderData()` is still acceptable for raw `<link>` tags (hints, preconnects)
   that have no `AssetCollector` equivalent.
3. **Processors stay single-responsibility** — `CssViewHelper` calls `ScssProcessor` then
   `MinificationProcessor` sequentially; it does not merge them.
4. **Criticality is a read-only concern at render time** — ViewHelpers query
   `AssetCriticalityResolver` but never write to `AboveFoldCacheService`. Only the observer
   middleware writes criticality state.
5. **TYPO3 page cache awareness** — any code that changes the criticality state for a PID
   must invalidate the page cache for that PID (handled by `CriticalCacheInvalidationListener`).
   ViewHelpers must not bypass this by writing to caches directly.
6. **Early hints are always additive** — `EarlyHintCandidateCollector::add()` is idempotent
   per `EarlyHintCandidate::key()` (currently derived from `rel`, `href`, and `as`). ViewHelpers
   may call it unconditionally; only candidates with the same full key are discarded as duplicates.
7. **`critical="auto"` must never throw** — if `pageUid` is 0 or observer data is unavailable,
   silently default to non-critical. Fail open, not closed.
8. **Loosely coupled services** — `AssetCriticalityResolver` knows about `AboveFoldCacheService`
   and `CriticalDetectionService` but neither knows about each other or about ViewHelpers.
   ViewHelpers know about `AssetCriticalityResolver` but not about the underlying detection chain.
9. **All new public methods covered by unit tests** before the step is marked complete.

---

## 9. Delivery Path Decision (assets-10)

*Resolved 2026-05-29. This documents the strategy for how compiled assets, early hints,
and cached pages reach the browser across different environments.*

### 9.1 Context

The extension publishes compiled CSS/JS to `public/typo3temp/assets/mai_assets/compiled/{hash}.css`,
computes SRI hashes, registers files with TYPO3's `AssetCollector`, and maintains an early
hints manifest cache. The question is how these outputs get delivered to browsers — through
Apache `.htaccess` rules, PHP middleware, or a hybrid of HTTP 103 Early Hints + static files.

### 9.2 Decision: Hybrid — Apache direct serving + HTTP 103 Early Hints

**For compiled assets (CSS, JS, images):** Apache serves them directly from the filesystem.
Content-hash filenames make them immutable, so the existing `public/.htaccess` `mod_expires`
rules (`ExpiresByType text/css "access plus 1 year"`, `mod_deflate` compression, CORS for
fonts/images) handle delivery with zero PHP overhead. No middleware code is needed.

**For HTTP 103 Early Hints:** The `EarlyHintsMiddleware` — already operational — sends
`Link` headers with HTTP status 103 for GET requests where a cached early hints manifest
exists for the current page+language. 103 is disabled in Development context (DDEV) due to
a known `mod_proxy_fcgi` bug where Link headers are auto-promoted to 103 responses before
`mod_headers` can suppress them, causing downstream proxies (e.g. Traefik) to fail with 500.

**For full HTML page caching in production:** The project may optionally use
`EXT:staticfilecache`. In that case the `.htaccess` redirect path (PrepareMiddleware →
GenerateMiddleware → `.htaccess` template) writes `Link` headers alongside cached HTML,
delivering the same preload hints without PHP on subsequent visits. The `FallbackMiddleware`
is kept enabled as a universal fallback for environments that lack `.htaccess` support
(Nginx, Caddy, etc.).

### 9.3 Layer assignment

| Layer | Delivery mechanism | Implemented? |
|---|---|---|
| Compiled CSS / JS | Apache direct filesystem serving (`public/` → `mod_expires` + `mod_deflate`) | ✅ (status quo — `CompiledAssetPublisher` publishes to `typo3temp/assets/mai_assets/compiled/`) |
| HTTP 103 Early Hints | `EarlyHintsMiddleware` (PSR-15, after `page-resolver`, before `page-argument-validator`) | ✅ (sends `header('Link: …', false, 103)` from cached manifest; skipped in Development context) |
| Early hint manifest storage | `EarlyHintManifestListener` → `EarlyHintCacheService` (TYPO3 cache `mai_assets_early_hints`, keyed by `pageUid_languageUid`) | ✅ |
| Full-page static HTML (Apache) | `EXT:staticfilecache` `.htaccess` redirect (Prepare + Generate middleware → `Htaccess.html` template) | Optional; production config |
| Full-page static HTML (universal) | `EXT:staticfilecache` `FallbackMiddleware` (PHP serves HTML + reads `.config.json` headers) | Enabled by default in staticfilecache (`useFallbackMiddleware=1`) |

### 9.4 Comparison: staticfilecache PrepareMiddleware vs FallbackMiddleware

| Aspect | `PrepareMiddleware` | `FallbackMiddleware` |
|---|---|---|
| **Execution point** | POST-render (after `handler->handle()`) — tags the response | PRE-render (early in chain, before `timetracker`) — may short-circuit |
| **Position in stack** | After `page-resolver`, `prepare-tsfe-rendering`; before `cache-timeout` | Before `timetracker` (earliest possible intercept) |
| **Purpose** | Annotate response with `X-SFC-Tags` / `X-SFC-Cachable`, inline assets, emit HTTP/2 push `Link` headers | Serve cached HTML file directly, bypass TYPO3 page rendering entirely |
| **Delivery mechanism** | Works with `.htaccess` generator — mod_rewrite, `ForceType`, `mod_headers` serve static HTML + stored headers | PHP reads static file + `.config.json` headers → `HtmlResponse` |
| **Web server dependency** | Apache only (`.htaccess` + mod_rewrite + mod_headers + mod_expires) | Universal (Apache, Nginx, Caddy, PHP dev server) |
| **Nginx support** | ❌ No `.htaccess`; requires PHP generator (`enableGeneratorPhp=1`) + Nginx `try_files` config | ✅ Works natively |
| **Performance** | Best — Apache serves HTML without booting PHP/TYPO3 at all | ~50ms overhead (PHP bootstrap + middleware chain up to early position) |
| **Header storage** | `Header set {name} "{value}"` in per-file `.htaccess` | JSON key-value in per-file `.config.json` |
| **Cache invalidation** | mod_rewrite `TIME` check → rewrite to `/index.php` when expired | PHP `time()` comparison against `invalidAtTimestamp` in `.config.json` |
| **Content-Encoding** | Apache `mod_negotiation` handles `.gz`/`.br` variants via `MultiViews` or explicit `RewriteCond` | PHP checks `Accept-Encoding` header, serves `.gz`/`.br` variant with `Content-Encoding` header |
| **Link / Early Hints** | Stored as `Header set Link "…"` in `.htaccess` (sent as response header, not 103) | Stored in `.config.json`, served as response header |

### 9.5 Environment matrix: DDEV vs Production

| Concern | DDEV (Development) | Production (Apache) | Production (Nginx) |
|---|---|---|---|
| **Web server** | Apache + mod_rewrite + mod_proxy_fcgi | Apache + mod_rewrite | Nginx |
| **HTTP 103 Early Hints** | ❌ Disabled — `Environment::getContext()->isDevelopment()` check in `EarlyHintsMiddleware` avoids mod_proxy_fcgi auto-103 bug | ✅ Enabled — `EarlyHintsMiddleware` sends `header('Link: …', false, 103)` | ⚠️ Requires special Nginx config (103 is non-trivial; typically deprecated in favor of `Link` response headers) |
| **Compiled asset delivery** | Apache direct from `typo3temp/assets/mai_assets/compiled/` (same as production) | Apache direct + `mod_expires` 1-year cache | Nginx `try_files` from same directory + `expires` directive |
| **Full-page HTML cache** | TYPO3 internal page cache only (staticfilecache not needed in dev) | staticfilecache `.htaccess` redirect OR `FallbackMiddleware` | staticfilecache `FallbackMiddleware` + Nginx `try_files` config (see staticfilecache docs) |
| **`.htaccess` support** | ✅ (DDEV default: `AllowOverride All`) | ✅ (typical TYPO3 hosting) | ❌ (use `FallbackMiddleware` + PHP generator) |
| **Recommended approach** | PHP rendering (no static HTML cache), no 103, asset filesystem serving | **Hybrid:** `.htaccess` static HTML from staticfilecache + 103 Early Hints from `EarlyHintsMiddleware` | FallbackMiddleware for HTML + Link response headers (no 103) |

### 9.6 Rationale for the hybrid decision

1. **Assets are already optimally served.** Content-hash filenames (`{sha256}.css`) make every
   compiled output immutable. Apache's `mod_expires` sets `Cache-Control: max-age=31536000`.
   There is no value in adding a PHP middleware just to serve these files — it would add
   latency with no benefit.

2. **103 Early Hints are the right granularity for critical assets.** The `EarlyHintsMiddleware`
   sends hints *before* TYPO3 renders, so the browser can preload CSS/JS/images while the
   server does the heavy lifting. This is more aggressive than staticfilecache's
   `HttpPushService` (which adds Link headers post-render) and more targeted than blanket
   `.htaccess` Link headers (which hint everything). The mai_assets approach only hints
   assets that are actually observed as above-fold-critical on that page.

3. **`.htaccess` static HTML is the fallback, not the primary layer.** The extension's
   early hints + observer pipeline already provides the real performance win on uncached
   requests. Static HTML caching (via staticfilecache or another mechanism) is a production
   optimization that should be configured per-environment, not baked into mai_assets code.
   Nothing in mai_assets depends on or requires staticfilecache.

4. **`FallbackMiddleware` is the safety net.** When `.htaccess` is not available (Nginx,
   misconfigured Apache), the staticfilecache `FallbackMiddleware` still serves cached
   pages with correct headers. This is slower than Apache but universal.

### 9.7 Implications for mai_assets code

- **No new middleware needed.** The `CompiledAssetPublisher` already publishes to the
  public filesystem. Apache serves those files directly. The `EarlyHintsMiddleware` already
  sends 103s. No additional interception layer is required.
- **No `.htaccess` generation in mai_assets.** The extension must never write `.htaccess`
  files — that is the web server / staticfilecache's concern. mai_assets publishes to
  a standard public cache directory; the web server configuration handles delivery.
- **No HTTP 103 in DDEV dev.** The `Environment::getContext()->isDevelopment()` guard in
  `EarlyHintsMiddleware` is the correct and sufficient workaround for the mod_proxy_fcgi
  bug. No additional configuration or detection is needed.
- **103 is Apache-only for now.** The `EarlyHintsMiddleware` uses PHP's `header()` with
  status code 103, which only works through Apache + mod_proxy_fcgi (when not in dev).
  Nginx typically does not forward 103 from PHP-FPM. This is acceptable — Nginx production
  deployments should use Link response headers from `FallbackMiddleware` or Nginx's
  native `add_header Link` instead.
