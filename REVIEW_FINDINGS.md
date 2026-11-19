# Review: offene Punkte (November 2026)

Ergebnis einer vollständigen Durchsicht aller Klassen unter `Classes/`, der
TypoScript-Konfiguration und der Middleware-Reihenfolge. Status: `[ ]` offen,
`[x]` erledigt (mit Commit/Test-Hinweis). Stand: K1–N27 erledigt, Abschnitte
„Weitere Befunde“ und „Restrisiken“ offen.

## Abgleich mit dem ursprünglichen Review-Bericht

Jeder Punkt des Berichts ist hier erfasst (Bericht-Nr. → Eintrag):

| Bericht | Eintrag | Bericht | Eintrag |
|---|---|---|---|
| 1 Save-Hook Content-UID | K1 | 12 Inline-CSS + Preload / ganzes Stylesheet inline | M12, W33 |
| 2 Kein Leeren bei Änderungen | K2 | 13 Relative `url()` | M13, W34 |
| 3 Route-Enhancer teilen Datei | K3 | 14 Event doppelt | M14 |
| 4 SCSS-Partials + Minify | K4 | 15 `Vary` / CSP- und Cache-Header / doppelte Kodierung | M15, W28, W29 |
| 5 DataProcessor läuft nie | H5 | 16 Seiten ohne Manifest | M16 |
| 6 Above-fold flattert | H6 | 17 Inline-Skripte ohne Nonce | M17 |
| 7 Font-Preload Dateipfad | H7 | 18 `str_ireplace` | M18 |
| 8 Multi-Site erste Site | H8 | 19 `preg_replace` / ID-Kollisionen / USER_INT-Icons | M19, W40, W41 |
| 9 Warmup-Paginierung | H9 | 20 Positions-Heuristik | M20 |
| 10 Bild-Preloads | H10 | 21 Icon-Provider | M21 |
| 11 JS verliert `defer` | H11 | Niedrig: Video `class` / Poster lazy / JS fehlt | N22, W47, W48 |
| Niedrig: 103 | N24 | Niedrig: CssViewHelper ohne Request | N23 |
| Niedrig: tote Einstellungen, Warmup-Meldung | N25, N27 | Niedrig: Viewport-Grenzen im JS | N26 |
| Niedrig: `compiled/` wächst | N27 | Niedrig: Baseline im Vendor-Ordner | W53 |

W-Einträge ohne Bericht-Nummer stammen aus den Arbeitsnotizen des Reviews.

## Kritisch – falsche oder veraltete Inhalte für Besucher

- [x] **K1 Save-Hook übergibt Content-UID als Page-UID**
  `Classes/Hook/ContentElementSaveHook.php` ruft
  `invalidateAfterContentSave($uid, …)` mit der tt_content-UID auf. Geleert
  werden Cache, statische Datei und Above-fold-Daten der Seite mit
  ID = Content-UID; die bearbeitete Seite bleibt veraltet.
  → Erledigt: `ContentPageResolver` ermittelt die Seite des Elements.
- [x] **K2 Statischer Cache wird bei redaktionellen Änderungen nicht geleert**
  Außer K1 gibt es keinen Weg, der statische Dateien entfernt: Seiteneigenschaften
  (Titel/Menüs), Löschen/Verschieben/Kopieren/Ausblenden (cmdmap), Datensätze
  anderer Tabellen, „Frontend-Caches leeren“ und `starttime`/`endtime`.
  → Erledigt: `StaticFileIndex` (Cache `mai_assets_static_index`, Gruppe `pages`, gleiche
  Tags und Lebensdauer wie der Page-Cache). Ohne gültigen Eintrag wird die Datei nicht
  ausgeliefert und gelöscht. Zusätzlich `clearCachePostProc`-Hook und `CacheFlushEvent`-Listener.
- [x] **K3 Route-Enhancer-URLs teilen sich eine statische Datei**
  Schlüssel ist nur `{pageUid}_{languageUid}`. `/news/artikel-a` wird für alle
  Artikel ausgeliefert. Gleiches Problem beim Early-Hint-Manifest.
  → Erledigt (überarbeitet): Jede URL-Variante einer Seite ist ein eigenes Dokument.
  `StaticCacheRequestGuard::variantOf()` bildet aus typeNum und Route-Argumenten einen
  Schlüssel; Datei (`{page}_{lang}/v/{variant}/index.html`), Index-Eintrag und
  Early-Hint-Manifest (`earlyhints_{page}_{lang}_{variant}`) hängen daran. News-Detailseiten
  werden damit gecacht wie jede andere Seite; ihre eigenen Cache-Tags (z. B. `tx_news_uid_7`)
  invalidieren nur ihre Variante. (Die erste Lösung schloss solche URLs aus.)
- [x] **K4 SCSS-Partials werden bei aktiver Minifizierung nicht übernommen**
  Der Minifier-Cache hängt am Hash der Root-`.scss`-Datei statt am Inhalt
  (`AbstractAssetProcessor`, `CompiledAssetPublisher`).
  → Erledigt: Cache-Schlüssel enthält den Inhalt; Publisher-Schlüssel zusätzlich den Quellpfad.

## Hoch

- [x] **H5 `CriticalAssetDataProcessor` läuft nie**
  `tt_content.stdWrap.dataProcessing` existiert nicht; `{isCritical}` usw. sind in
  Templates nie gesetzt.
  → Erledigt: `lib.contentElement.dataProcessing.1763` (mit dem TYPO3-Parser geprüft);
  der Processor nutzt die gerenderte Seite statt `pid` (Referenzen).
- [x] **H6 Above-fold-Daten flattern**
  Jeder Report ersetzt die UID-Liste eines Buckets; unterschiedliche Viewport-Höhen
  im selben Bucket leeren Page-Cache und statische Datei bei jedem Wechsel.
  → Erledigt: Reports werden pro Bucket vereinigt (konvergiert); Inhaltsänderungen setzen zurück.
- [x] **H7 Font-Preload zeigt auf den Dateisystempfad**
  `FontPreloadCollector` schreibt den absoluten Serverpfad in `href`.
- [x] **H8 Multi-Site: Discovery nutzt die erste Site statt der aktuellen**
  `ExtensionConfigurationDiscovery::getCurrentSiteIdentifier()`.
- [x] **H9 Warmup-Task überspringt jeden zweiten Block**
  `StaticFileCacheWarmupTask`: Offset wandert über eine schrumpfende Ergebnismenge.
- [x] **H10 Bild-Preloads ohne `imagesrcset` laden doppelt**
  `ResponsiveImageViewHelper` preloadet überall die Desktop-AVIF,
  `PictureSourceRenderer` die erste (Mobile-)Variante.
  → Erledigt: Preloads mit `imagesrcset`/`imagesizes`/`media`/`type`, eines pro Media-Gruppe.
  In Chromium geprüft: vorher 2 Downloads auf dem Handy, jetzt 1.
- [x] **H11 „Kritisches“ JS verliert `defer`**
  `JsViewHelper` macht bei vorhandenen Observer-Daten alle `critical="auto"`-Skripte
  blockierend.
  → Erledigt: `defer` bleibt, kritisch bedeutet nur noch `fetchpriority="high"` + Preload.

## Mittel

- [x] **M12 Kritisches CSS wird inline eingefügt und zusätzlich vorgeladen**
- [x] **M13 Relative `url()` brechen** (ohne SCSS/Minify keine Umschreibung beim
  Inline-Einfügen; `?v=`/`#hash` werden nicht aufgelöst)
- [x] **M14 `BeforeAssetInjectionEvent` läuft beim kritischen CSS doppelt**
  → Erledigt: Das Event feuert nur noch im injizierenden ViewHelper (Inline-CSS, minifiziertes JS),
  nicht mehr im Processor. Extern eingebundenes CSS durchläuft es nicht mehr.
- [x] **M15 Statische Antworten ohne `Vary: Accept-Encoding`**
- [x] **M16 Seiten ohne Early-Hint-Manifest werden nie statisch gecacht**
- [x] **M17 Inline-Skripte (Above-fold, Image-Measure) ohne CSP-Nonce**
  → Erledigt: `nonce` aus dem Request; TYPO3 ersetzt ihn in gecachten Seiten. In Chromium mit
  strikter CSP geprüft.
- [x] **M18 `str_ireplace('</body>'|'</head>')` ersetzt alle Vorkommen**
  → Erledigt: `HtmlInjectionUtility` (letztes `</body>`, erstes `</head>`, erstes `<body>`).
- [x] **M19 Sprite-Einfügung per `preg_replace`** (`$1`/`\1` im SVG werden als
  Rückverweis interpretiert)
- [x] **M20 Positions-Heuristik zählt Übersetzungen und Workspace-Versionen mit**
- [x] **M21 Icon-Provider entfernt `width`/`height` auch an inneren Elementen**

## Niedrig

- [x] **N22 Video-Facade: doppeltes `class`-Attribut**
- [x] **N23 `CssViewHelper` ohne Null-Prüfung auf `$GLOBALS['TYPO3_REQUEST']`**
- [x] **N24 103 Early Hints** – `header(..., 103)` sendet unter PHP-FPM kein echtes 103
  → Erledigt: echtes 103 nur mit `headers_send()` (z. B. FrankenPHP), sonst immer
  `Link`-Header an der finalen Antwort (Proxies/CDNs leiten 103 daraus ab).
- [x] **N25 Tote Einstellungen** (`observerRootMargin`, `processingCacheLifetime`,
  ungenutzte TypoScript-Settings)
  → Erledigt: `observerRootMargin` wirkt (validiert, Standard jetzt `0px` = bisheriges Verhalten),
  `processingCacheLifetime` ist Standard für `maispace:assets:compiled:prune`, ungenutzte
  TypoScript-Settings entfernt. `config.namespaces.mai` schrieb ein falsches `xmlns:mai` ins
  `<html>`-Tag; ersetzt durch einen globalen Fluid-Namespace.
- [x] **N26 Viewport-Grenzen im JS fest eingetragen** (ignoriert `viewportBuckets`)
- [x] **N27 `typo3temp/assets/mai_assets/compiled/` wird nie aufgeräumt**
  → Erledigt: `maispace:assets:compiled:prune` (löscht alte Dateien, leert danach Page-Cache und
  statische Dateien). Der Warmup-Befehl wärmt jetzt genau die Datei, die `mai:asset.css` ausliefert.

## Nebenbefunde beim Abarbeiten

- [x] `Tests/Unit/ViewHelpers/Asset/CssViewHelperTest.php` hatte ein doppeltes `use`
  (Fatal Error, die Testsuite brach dort ab).
- [x] `GeneralUtility::resolveBackPath()` in `StaticFileCacheDirectory` ist ab TYPO3 v15 entfernt.
  → Ersetzt durch `Symfony\Component\Filesystem\Path::canonicalize()`.
- [x] Index-Einträge (K2) bekamen nicht alle Tags: TYPO3 14 setzt `pageId_*` (auch Übersetzung,
  Inhalte aus anderer Seite), die `cache_tags` der Seite und die Lebensdauer aus Start-/Stoppzeit
  erst nach `AfterCacheableContentIsGeneratedEvent`.
  → Behoben mit W31: Registrierung aus der fertigen Antwort mit den vollständigen Tags.

## Weitere Befunde aus dem Review (bisher nicht in der Liste)

### Statischer Cache und Auslieferung

- [x] **W28 Statische Antworten ohne CSP- und Cache-Header**
  `StaticFileServeMiddleware` antwortet vor `typo3/cms-frontend/csp-headers`; statisch
  ausgelieferte Seiten haben keine CSP und keine `Cache-Control`-/Sicherheits-Header.
  → Erledigt: Ein Recorder-Middleware (innerhalb der CSP-Middleware) speichert die Header der gerenderten Seite als `meta.json` neben der Datei; Auslieferung spielt sie wieder ab (`max-age`/`s-maxage` auf die Restlaufzeit gekürzt). CSP über `ContentSecurityPolicyHeaders::applyToResponse()` (TYPO3 14) mit Austausch des gespeicherten Nonce gegen den neuen. Neues HTML löscht veraltete Metadaten.
- [x] **W29 Doppelte Kodierung bei aktiver TYPO3-Output-Kompression**
  Eine `.br`/`.gz`-Variante mit `Content-Encoding` kann von der Frontend-Kompression ein
  zweites Mal gepackt werden.
  → Erledigt: Mit CSP oder aktiver Output-Kompression (`FE.compressionLevel`, `zlib.output_compression`) wird die unkomprimierte Datei ausgeliefert.
- [x] **W30 `Accept-Encoding` ignoriert `q=0`**
  `br;q=0` wird als Brotli-Unterstützung gelesen.
  → Erledigt: `Accept-Encoding` wird mit q-Werten ausgewertet (`q=0` = abgelehnt, `*` berücksichtigt).
- [x] **W31 Statische Datei entsteht nur beim Erzeugen des Page-Caches**
  Ist der Page-Cache warm, schreibt weder ein Besucher noch der Warmup-Request die Datei;
  sie entsteht erst nach dem nächsten Ablauf/Leeren des Page-Caches.
  → Erledigt: `StaticFileWriterMiddleware` schreibt die Datei aus der fertigen Antwort, nach frischem Rendern und nach einem Page-Cache-Treffer (füllt fehlende/ungültige Dateien, gültige bleiben). Der Event-Listener ist nur noch Fallback für TYPO3 ohne `frontend.page.parts`.
- [ ] **W32 Jede Inhaltsänderung setzt die Above-fold-Daten zurück**
  `InvalidationService` löscht bei jedem Speichern (auch reiner Textänderung) die
  Bucket-Daten; die Seite verliert damit ihre Bereitschaft und den statischen Cache, bis
  alle Viewport-Bereiche erneut gemeldet haben.

### Assets (CSS/JS)

- [ ] **W33 Kritisches CSS: ganzes Stylesheet inline an der ViewHelper-Position**
  Bei `critical="auto"` reicht irgendein Observer-Eintrag der Seite, und jede Datei wird
  vollständig als `<style>` dort eingefügt, wo der ViewHelper steht (ggf. im `<body>`).
- [ ] **W34 Relative `url()` ohne existierendes Ziel bleiben relativ**
  Im kompilierten CSS unter `typo3temp/…/compiled/` zeigen sie danach ins Leere.
- [ ] **W35 `getPublicWebPath()` mit projektspezifischem Sonderfall**
  Hart codiertes `packages/typo3-extension-*` → `vendor/maispace/*`; absoluter Pfad mit
  führendem `/` bricht Installationen in Unterverzeichnissen.
- [ ] **W36 `JsViewHelper` kennt `integrity="none"` nicht**
  Der CSS-ViewHelper schaltet SRI damit ab, der JS-ViewHelper gibt `integrity="none"` aus.
- [ ] **W37 Nicht öffentliche Quellen werden nicht veröffentlicht**
  JS/CSS aus `Resources/Private` (ohne Minify/SCSS) wird mit einem nicht erreichbaren
  Pfad eingebunden (404).
- [ ] **W38 SRI-Hash wird bei jedem Rendern neu berechnet** (kein Cache; Performance)
- [ ] **W39 Dev-Server standardmäßig im Development-Kontext aktiv**
  `useDevServer = auto` lädt in jedem Development-Kontext von `localhost:5173`, auch ohne
  laufenden Vite-Server (alle Assets fehlen).

### SVG und Icons

- [ ] **W40 ID-Kollisionen im Sprite** – interne IDs (Gradients, Clip-Paths, Masks) mehrerer
  Symbole kollidieren; gleiches Problem bei mehrfach eingebetteten Inline-SVGs.
- [ ] **W41 Icons aus USER_INT-Bereichen fehlen im Sprite** (Sprite entsteht vor dem
  Rendern der ungecachten Teile).
- [ ] **W42 Sprite kann doppelt im Dokument landen** (`mai:svg.sprite` plus automatisches
  Einfügen durch den Listener → doppelte IDs).
- [ ] **W43 Kinder in anderen XML-Namespaces fallen aus dem Sprite**
  `SimpleXMLElement::children()` liefert nur den Default-Namespace.

### HTML-Minifizierung

- [ ] **W44 Verschachtelte geschützte Blöcke gehen verloren**
  Ein `<script>`/`<style>`/`<textarea>` innerhalb von `<pre>`/`<code>` bleibt als
  `<!--PROTECTED_n-->` stehen (`strtr()` ersetzt nur einmal).
- [ ] **W45 Ungecachte Seiten werden zweimal minifiziert** (Listener und Middleware).

### Bilder und Video

- [ ] **W46 `fetchpriority` an `<source>`** – kein gültiges Attribut (wirkungslos).
- [ ] **W47 Video-Facade: Poster immer `loading="lazy"`**, auch als LCP-Bild above the fold.
- [ ] **W48 Video-Facade/Lazy-Video brauchen JavaScript, das die Extension nicht mitliefert**
  (`<template>`-iframe, `data-lazy`/`data-src`).
- [ ] **W49 YouTube-Poster `maxresdefault.jpg` existiert nicht für jedes Video**
  (graues 120×90-Platzhalterbild).
- [ ] **W50 YouTube-/Vimeo-ID wird nicht validiert** (nur HTML-escaped, nicht URL-codiert).
- [ ] **W51 Codec-Varianten (`.av1.mp4`/`.hevc.mp4`) werden selten gefunden**
  URL-codierte Dateinamen und nicht lokale Storages (CDN) scheitern an `file_exists()`;
  die Prüfung läuft bei jedem Rendern.
- [ ] **W52 Bilder ohne `elementUid` teilen einen Messschlüssel** (`e0.f…`): dieselbe Datei
  in verschiedenen Layouts (Header/Footer) mischt ihre Breiten.

### Sonstiges

- [ ] **W53 Regressionsbefehl schreibt die Baseline in den Extension-Ordner**
  (`EXT:mai_assets/Resources/Private/Baselines/`, bei Composer im read-only `vendor/`).
- [ ] **W54 Je Bild zwei DB-Abfragen in `CriticalDetectionService`** (keine Zwischenspeicherung).
- [ ] **W55 Rate-Limit der Report-Endpunkte schreibt bei jedem Request in den DB-Cache.**
- [ ] **W56 Warmup-Queue löscht jeden Lauf alle verarbeiteten Einträge** (keine Historie
  für Fehleranalyse).
- [ ] **W58 Einstellungen ohne Eintrag in `ext_conf_template.txt`**
  `viewportBuckets`, `criticalThresholdByColPos`, `svgStripAttributes`, `fontPreloadFormats`
  sind nur über `settings.php` setzbar und im Backend nicht sichtbar.
- [ ] **W57 Backend-Bericht ignoriert Seitenrechte** (nur relevant, falls das Modul nicht
  mehr auf Admins beschränkt ist).

## Restrisiken der erledigten Punkte

- [ ] **R1 TYPO3 v12 ungetestet** – Tag/Lebensdauer-Übernahme für den statischen Index über
  `$GLOBALS['TSFE']`, `CacheFlushEvent`, `ConsumableNonce`.
- [ ] **R2 Echtes 103 nur theoretisch geprüft** (FrankenPHP `headers_send()`).
- [ ] **R3 H6: Liste kritischer Elemente wächst** bis zur nächsten Inhaltsänderung
  (seltene sehr hohe Viewports markieren dauerhaft mehr Elemente).
- [ ] **R4 H8: Im CLI gelten nur Konfigurationen mit `sites => ['*']`**
  (z. B. Sprite im Warmup-Befehl ohne site-spezifische Icons).
- [ ] **R5 H10: Browser ohne AVIF bekommen keinen Bild-Preload.**
- [ ] **R6 M14: Extern eingebundenes CSS durchläuft `BeforeAssetInjectionEvent` nicht mehr**
  (bestehende Listener prüfen).
- [ ] **R7 PHPStan und PHP-CS-Fixer liefen lokal nicht** (nur in der CI).
- [ ] **R8 CSP für statische Antworten nur per Unit-Test geprüft**: Nonce-Austausch und
  Header-Logik getestet, der Aufruf von `applyToResponse()` mit echter Site-CSP-Konfiguration
  nicht (braucht einen Functional-Test mit TYPO3). In TYPO3 12/13 fehlt die Methode; dort
  bleibt die statische Antwort ohne CSP.
