# LCP hero image audit (assets-7 / qa-perf perf-08, perf-09)

Audit date: 2026-05-29. Checks: `perf-08` (responsive srcset + modern formats), `perf-09` (`fetchpriority="high"` on LCP image).

## Summary

| Location | CType / context | fetchpriority | AVIF/WebP srcset | Action |
| --- | --- | --- | --- | --- |
| `mai_theme` `Hero.html` | `maispace_hero` | Yes (`critical="auto"` + `elementUid`) | Yes (explicit sources) | OK — reference implementation |
| `mai_theme` `Banner.html` | `maispace_banner` | **Was missing** (`f:image` + `loading="lazy"`) | **Was missing** | **Fixed** — `mai:image.picture` + auto sources |
| `mai_theme` `ListItem.html`, `SliderItem.html` | listings / slider | Only when above-fold | **Was missing** (picture without sources) | **Fixed** — `PictureViewHelper` default sources |
| `mai_theme` `Atom/Picture.html`, `Figure.html` | partials | Depends on `elementUid` | **Was missing** without child sources | **Fixed** — default sources |
| `mai_gallery` `Gallery/Image.html` | gallery detail | N/A (below fold) | **Broken** (invalid `breakpoints` arg) | **Fixed** — `width` + `sizes` |
| Other `f:image` usages | cards, teasers, news | No (not LCP) | No | Out of scope for hero audit |

## ViewHelper changes (`mai_assets`)

- `PictureViewHelper`: when no `<mai:image.picture.source>` children are rendered, emits default AVIF/WebP srcsets aligned with `Hero.html` (767px / 768px breakpoints, widths 400/800 mobile and 75%/100% of `width` desktop).
- New `sizes` argument (default `100vw`).
- `PictureSourceRenderer` service: shared markup builder for `SourceViewHelper` and defaults.

## Verification

1. Homepage with `maispace_hero`: view source — `<source type="image/avif"` with `fetchpriority="high"` on critical render; fallback `<img loading="eager" fetchpriority="high"`.
2. Page with `maispace_banner` as first media: same pattern when element is above-fold.
3. `composer test` and `composer lint:check` in `typo3-extension-assets`.
