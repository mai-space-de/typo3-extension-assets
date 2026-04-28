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
- `ContentElementSaveHook` already flushes `maiAssetsAboveFold_{pageUid}` tags when a
  content element is saved. This also triggers page cache invalidation, so the next uncached
  render picks up new observer data.
- `AboveFoldCacheService::updateCriticalUids()` calls `AfterCriticalUidsUpdatedEvent`;
  a new listener `CriticalCacheInvalidationListener` should flush the TYPO3 page cache
  tags for the affected PID so a fresh render (with inlined CSS) is produced immediately
  after observer data arrives, without waiting for a manual cache clear.
- The early hints manifest (`mai_assets_early_hints` cache) is keyed by `pageUid + language`
  and is already invalidated by `EarlyHintManifestListener` on every page render. This is
  correct: the manifest is always rebuilt from the current render's `EarlyHintCandidateCollector`
  state.

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
| 15 | Unit tests for `AssetCriticalityResolver`, updated ViewHelpers, new listener | all above | One test class per changed class |

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
   (keyed by `rel+href`). ViewHelpers may call it unconditionally; duplicates are discarded.
7. **`critical="auto"` must never throw** — if `pageUid` is 0 or observer data is unavailable,
   silently default to non-critical. Fail open, not closed.
8. **Loosely coupled services** — `AssetCriticalityResolver` knows about `AboveFoldCacheService`
   and `CriticalDetectionService` but neither knows about each other or about ViewHelpers.
   ViewHelpers know about `AssetCriticalityResolver` but not about the underlying detection chain.
9. **All new public methods covered by unit tests** before the step is marked complete.
