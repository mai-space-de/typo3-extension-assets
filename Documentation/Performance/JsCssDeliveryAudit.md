# JS/CSS delivery audit (qa-perf-3 / perf-10, perf-11, perf-14)

Audit date: 2026-05-30. Checks: `perf-10` (JS defer/async), `perf-11` (critical CSS / render-blocking stylesheets), `perf-14` (third-party embed impact).

Static re-check:

```bash
python3 scripts/quality-audit/audit-perf-assets.py
python3 -m unittest scripts/quality-audit/test_audit_perf_assets.py
```

Output: `scripts/quality-audit/reports/perf-assets.tsv` + `perf-assets-summary.tsv`.

## Profiled CTypes

| CType | JS delivery | CSS delivery | Third-party |
|---|---|---|---|
| `maispace_accordion` | No JS (native `<details>`) | Per-CE `mai:asset.css` (`accordion-widget.scss`, ~2.6 KB source) | — |
| `maispace_slider` | Per-CE `mai:asset.js` with `defer="1"` (~5.9 KB) | Per-CE `mai:asset.css` (`slider-widget.scss`, ~5.0 KB source) | — |
| `maispace_tabs` | Per-CE `mai:asset.js` with `defer="1"` (~3.7 KB) | Per-CE `mai:asset.css` (`tabs-widget.scss`, ~2.8 KB source) | — |
| `maispace_map` | — | Map styles in `_content-elements.scss` | OSM iframe, `loading="lazy"` |
| `maispace_video` | — | Video styles in `_content-elements.scss` | YouTube/Vimeo iframes, `loading="lazy"` |

## Page layouts

| Layout | Critical CSS | Render-blocking gaps |
|---|---|---|
| `page_default` (`Default.html`) | Inline `:root` via `lib.themeVars` + self-hosted `@font-face` in `fonts.scss` | `mai:asset.css` uses `critical=auto` until observer data |
| `page_home` (`Landing.html`) | Same `HeadAssets` as `Default.html` | Same as `page_default` |

## Key findings

1. **Per-CE JS is correctly deferred** — Slider, tabs, modal inject `mai:asset.js` with `defer="1"` and `priority="0"` (footer, non-blocking).
2. **Widget CSS is per-CE** — Accordion/slider/tabs SCSS loads via `mai:asset.css` only when the CE is present (~10 KB source total when all three appear).
3. **Third-party embeds lazy-load** — Map and video iframes use `loading="lazy"`; main-thread cost remains on user interaction (`perf-14` medium).

## Follow-up tasks (not in scope for audit)

- Promote frequently used widget CSS into a deferred secondary bundle if above-fold observer data shows stable critical paths.
