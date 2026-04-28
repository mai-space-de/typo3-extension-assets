# Early Hints for `mai_assets`

## Goal

Use HTTP `103 Early Hints` to announce critical assets before TYPO3 finishes rendering the final HTML response.

This could reduce the time until the browser starts fetching:

- critical CSS
- critical JavaScript / module entry files
- critical fonts
- selected third-party origins via `preconnect`

## Why this extension is a good fit

This extension already knows a lot about critical-path assets:

- `mai:asset.hint` already models resource hints
- `mai:asset.criticalStyle` distinguishes critical vs. deferred CSS
- `mai:asset.preloadFont` and `Configuration/Fonts.php` describe font preloads
- `SvgSpriteCollector` and `FontPreloadCollector` already centralize asset registration

So the missing part is not the asset knowledge itself, but **when** that knowledge is available.

## Main constraint

`103 Early Hints` must be sent **before** the final response body is generated.

That means our current late-stage mechanisms are too late:

- `PageRenderer::addHeaderData()`
- `AfterCacheableContentIsGeneratedEvent`
- content post-processing / HTML injection into `<head>` or `<body>`

These are good for normal `<link>` tags in the final HTML, but not for `103`.

## What can realistically use Early Hints

### 1. Asset ViewHelpers

ViewHelpers are useful as the authoring API, but by themselves they are usually too late for first-request `103` hints because they run during rendering.

A workable approach would be:

1. keep ViewHelpers as the place where templates declare intent
2. collect hintable assets during rendering
3. persist the result in a page-specific cache/manifest
4. on the next request, emit `103 Early Hints` from middleware before rendering starts

This makes Early Hints primarily a **cached second-request optimization** for ViewHelper-driven assets.

Good candidates:

- critical CSS from `mai:asset.criticalStyle`
- explicit hints from `mai:asset.hint`
- critical JS/module preload hints, if added or registered centrally

### 2. Font registration

Fonts are the strongest candidate.

Because fonts can already be discovered from extension configuration, they do not have to depend on late template rendering. A middleware could resolve the active site/page context, read the configured fonts to preload, and emit:

- `Link: </path/to/font.woff2>; rel=preload; as=font; type=font/woff2; crossorigin=anonymous`

This should work especially well for:

- globally used brand fonts
- site-wide navigation/header fonts
- fonts declared in `Configuration/Fonts.php`

If we later want only a subset of configured fonts to be sent as Early Hints, the `Configuration/Fonts.php` contract would need to be extended with an explicit flag for that.

For fonts registered only through ViewHelpers, the same cached-manifest approach as above would still be needed.

### 3. SVG sprites

The current sprite is injected inline into the HTML body. That means there is no external sprite file for the browser to preload early.

So **the current inline sprite architecture does not benefit much from Early Hints**.

Early Hints would only become relevant for SVG sprites if we changed the model to:

- build a public sprite file
- reference it externally
- preload or preconnect to that resource origin

Without that architectural change, the sprite itself should stay out of the Early Hints scope.

### 4. Third-party origins

If templates or extension configuration know that critical assets come from external origins, Early Hints could also send `preconnect` hints early.

This is a good fit for:

- font CDNs
- video/image CDNs
- external asset hosts

## TYPO3 integration idea

### Middleware

Early Hints belong in the frontend middleware stack, because middleware runs before the final HTML response is produced.

The middleware would ideally:

1. run only for cacheable frontend page requests
2. determine site, page, language, and relevant variant
3. load a cached list of early-hint candidates
4. emit `103` headers
5. continue normal request handling

Important: the middleware should be conservative and only emit hints that are:

- stable for the current page variant
- actually critical
- safe to preload repeatedly

## `HttpUtility`

`TYPO3\CMS\Core\Utility\HttpUtility` is the obvious TYPO3-related place to look at for low-level HTTP response handling, but for Early Hints we should treat it as an implementation detail rather than the design center.

The design should be:

- middleware decides **what** to hint
- a low-level HTTP utility / emitter decides **how** to send `103`

If TYPO3 core already supports interim responses through `HttpUtility` or a related response-emission layer, we should reuse that. If not, this extension should avoid building fragile SAPI-specific behavior on its own.

## Recommended design

### Option A: Cached page manifest

Best fit for this extension.

- During normal rendering, collect all hintable critical assets
- Store them in a cache entry keyed by page/site/language/context
- On later requests, middleware reads that manifest and emits `103 Early Hints`

Pros:

- works with existing ViewHelpers
- keeps template API unchanged
- avoids guessing before rendering

Cons:

- first request cannot benefit
- cache invalidation must follow page/content changes

### Option B: Configuration-driven hints

Best for fonts and global assets.

- Read from extension/site configuration before rendering
- Emit `103` immediately in middleware

Pros:

- can work on the first request
- simple and robust

Cons:

- less precise than per-page collection
- risks over-hinting if configured too broadly

### Option C: Hybrid model

Most realistic overall.

- configuration-driven Early Hints for global fonts and origins
- cached manifest Early Hints for page-specific CSS/JS declared by ViewHelpers
- no Early Hints for inline SVG sprites

## Practical recommendation

For this extension, the best next step would be:

1. introduce an internal `EarlyHintCandidateCollector`
2. let `HintViewHelper`, `CriticalStyleViewHelper`, and font registration feed that collector
3. persist candidates into a page-variant cache during normal rendering
4. add a frontend middleware that reads the cache and emits `103` when supported
5. start with fonts and critical styles
6. explicitly exclude inline SVG sprites for now

## Conclusion

Early Hints are a strong match for this extension, but only if we move the decision point from late HTML injection to an earlier middleware phase.

In short:

- **fonts:** yes, best candidate
- **critical CSS / explicit asset hints:** yes, via cached manifest
- **SVG sprites:** not with the current inline approach
- **TYPO3 implementation:** middleware first, `HttpUtility` only as transport detail
