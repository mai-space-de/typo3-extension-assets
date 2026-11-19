# Review: offene Punkte (November 2026)

Ergebnis einer vollständigen Durchsicht aller Klassen unter `Classes/`, der
TypoScript-Konfiguration und der Middleware-Reihenfolge. Status: `[ ]` offen,
`[x]` erledigt (mit Commit/Test-Hinweis).

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
  → Erledigt: `StaticCacheRequestGuard::isPlainPageDocument()` (typeNum + Route-Argumente)
  in Writer, Serve-Middleware, Early-Hint-Manifest und -Middleware.
- [x] **K4 SCSS-Partials werden bei aktiver Minifizierung nicht übernommen**
  Der Minifier-Cache hängt am Hash der Root-`.scss`-Datei statt am Inhalt
  (`AbstractAssetProcessor`, `CompiledAssetPublisher`).
  → Erledigt: Cache-Schlüssel enthält den Inhalt; Publisher-Schlüssel zusätzlich den Quellpfad.

## Hoch

- [ ] **H5 `CriticalAssetDataProcessor` läuft nie**
  `tt_content.stdWrap.dataProcessing` existiert nicht; `{isCritical}` usw. sind in
  Templates nie gesetzt.
- [ ] **H6 Above-fold-Daten flattern**
  Jeder Report ersetzt die UID-Liste eines Buckets; unterschiedliche Viewport-Höhen
  im selben Bucket leeren Page-Cache und statische Datei bei jedem Wechsel.
- [x] **H7 Font-Preload zeigt auf den Dateisystempfad**
  `FontPreloadCollector` schreibt den absoluten Serverpfad in `href`.
- [x] **H8 Multi-Site: Discovery nutzt die erste Site statt der aktuellen**
  `ExtensionConfigurationDiscovery::getCurrentSiteIdentifier()`.
- [ ] **H9 Warmup-Task überspringt jeden zweiten Block**
  `StaticFileCacheWarmupTask`: Offset wandert über eine schrumpfende Ergebnismenge.
- [ ] **H10 Bild-Preloads ohne `imagesrcset` laden doppelt**
  `ResponsiveImageViewHelper` preloadet überall die Desktop-AVIF,
  `PictureSourceRenderer` die erste (Mobile-)Variante.
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
- [ ] **M17 Inline-Skripte (Above-fold, Image-Measure) ohne CSP-Nonce**
- [ ] **M18 `str_ireplace('</body>'|'</head>')` ersetzt alle Vorkommen**
- [ ] **M19 Sprite-Einfügung per `preg_replace`** (`$1`/`\1` im SVG werden als
  Rückverweis interpretiert)
- [ ] **M20 Positions-Heuristik zählt Übersetzungen und Workspace-Versionen mit**
- [ ] **M21 Icon-Provider entfernt `width`/`height` auch an inneren Elementen**

## Niedrig

- [ ] **N22 Video-Facade: doppeltes `class`-Attribut**
- [x] **N23 `CssViewHelper` ohne Null-Prüfung auf `$GLOBALS['TYPO3_REQUEST']`**
- [ ] **N24 103 Early Hints** – `header(..., 103)` sendet unter PHP-FPM kein echtes 103
  (nur prüfen/dokumentieren)
- [ ] **N25 Tote Einstellungen** (`observerRootMargin`, `processingCacheLifetime`,
  ungenutzte TypoScript-Settings)
- [ ] **N26 Viewport-Grenzen im JS fest eingetragen** (ignoriert `viewportBuckets`)
- [ ] **N27 `typo3temp/assets/mai_assets/compiled/` wird nie aufgeräumt**

## Nebenbefunde beim Abarbeiten

- [x] `Tests/Unit/ViewHelpers/Asset/CssViewHelperTest.php` hatte ein doppeltes `use`
  (Fatal Error, die Testsuite brach dort ab).
- [ ] `GeneralUtility::resolveBackPath()` in `StaticFileCacheDirectory` ist ab TYPO3 v15 entfernt.
