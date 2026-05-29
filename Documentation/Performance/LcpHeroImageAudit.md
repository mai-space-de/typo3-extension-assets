# LCP hero image audit (assets-7 / qa-perf-2 / perf-08, perf-09)

Audit date: 2026-05-29. Checks: `perf-08` (responsive srcset + modern formats + explicit dimensions), `perf-09` (`fetchpriority="high"` on LCP image).

Static re-check: `python3 scripts/quality-audit/audit-perf-images.py` → `scripts/quality-audit/reports/perf-images.tsv`.

## Summary

| Location | CType / context | fetchpriority | AVIF/WebP srcset | width/height | Action |
| --- | --- | --- | --- | --- | --- |
| `mai_theme` `Hero.html` | `maispace_hero` | Yes (`critical="auto"` + `elementUid`) | Yes (explicit sources) | Yes (VH + width arg) | OK — reference implementation |
| `mai_theme` `Banner.html` | `maispace_banner` | Yes (`critical="auto"`) | Yes (auto sources) | Yes (VH + width arg) | OK |
| `mai_theme` `ListItem.html`, `SliderItem.html` | listings / slider | Only when above-fold | Yes (default sources) | Yes (VH from processed file) | OK |
| `mai_theme` `Atom/Picture.html`, `Figure.html` | partials | Depends on `elementUid` | Yes (default sources) | Yes (VH) | OK |
| `mai_gallery` `Gallery/Image.html` | gallery detail | N/A (below fold) | Yes (auto sources) | Yes (`width="800"`) | OK |
| `mai_gallery` `Gallery/Card.html` | gallery listing | N/A | **Was broken** (invalid `breakpoints` arg) | **Was missing width** | **Fixed** — `width="400"` + auto sources |
| `mai_theme` `Gallery.html` | `maispace_gallery` CE | N/A | No (`f:image`) | Partial (TYPO3 processing) | Info — grid thumbs; not LCP |
| `mai_locations` `List.html`, `Detail.html` | location plugin | N/A | No (`f:image`) | Yes (`width`/`height` on `f:image`) | Info — cover thumbs; map is JS widget (no image) |
| Other `f:image` usages | cards, teasers, news | No (not LCP) | No | Varies | Out of scope for LCP hero audit |

## ViewHelper changes (`mai_assets`)

- `PictureViewHelper`: when no `<mai:image.picture.source>` children are rendered, emits default AVIF/WebP srcsets aligned with `Hero.html` (767px / 768px breakpoints, widths 400/800 mobile and 75%/100% of `width` desktop).
- Fallback `<img>` now includes `width`/`height` from explicit arguments or the processed FAL file (perf-08 CLS).
- New `sizes` argument (default `100vw`).
- `PictureSourceRenderer` service: shared markup builder for `SourceViewHelper` and defaults; critical sources get `fetchpriority="high"`.

## Gallery / map review

- **Gallery plugin** (`mai_gallery`): detail images and listing cards route through `mai:image.picture` with auto AVIF/WebP srcsets. Theme CE gallery grid still uses `f:image` for lightbox thumbs (acceptable — below fold, not LCP).
- **Locations / map** (`mai_locations`): cover images use TYPO3 `f:image` with crop dimensions; the map block is an empty `div` filled by JS (`data-lat` / `data-lng`) — no raster map tiles in Fluid. Not an LCP candidate.

## Verification

1. Homepage with `maispace_hero`: view source — `<source type="image/avif"` with `fetchpriority="high"` on critical render; fallback `<img loading="eager" fetchpriority="high" width="…" height="…">`.
2. Page with `maispace_banner` as first media: same pattern when element is above-fold.
3. Gallery listing card: `<picture>` with AVIF/WebP sources and sized fallback img.
4. `python3 -m unittest scripts/quality-audit/test_audit_perf_images.py`
5. `composer test` and `composer lint:check` in `typo3-extension-assets`.
