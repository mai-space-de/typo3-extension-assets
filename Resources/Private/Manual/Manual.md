---
version: "1.0"
date: 2026-11-06
---

# Handbuch: Assets

Styles, Skripte, Schriften, SVG-Sprites und responsive Bilder laufen über diese Extension. SCSS wird serverseitig kompiliert, CSS und JavaScript können minifiziert werden.

## Was Redaktion beachten sollte

Bilder in Inhaltselementen werden responsive ausgeliefert. Ein SVG-Sprite bündelt die Icons des Themes.

Subresource Integrity setzt Prüfsummen auf lokale Dateien. Für die Look-Vorschau darf die Prüfsumme mit dem Wert `none` ausgeschaltet werden, sonst bleibt die Vorschau ohne Styles.

## Above-the-fold

Die Extension merkt sich, welche Inhaltselemente beim Laden im sichtbaren Bereich liegen, und lädt deren Bilder mit hoher Priorität. Im Tab **Render as Critical** lässt sich das pro Element überschreiben: **Automatisch (gemessen)** ist der Standard, **Ja** bzw. **Nein** erzwingen die Entscheidung.

Kritisches CSS (`mai:asset.css` mit `critical="auto"`) wird nur bis zur Größe `criticalCssMaxInlineBytes` (Standard 14 KB) direkt ins HTML geschrieben; größere Dateien werden normal eingebunden. Den ViewHelper im `<head>` des Layouts aufrufen, die Ausgabe erscheint an seiner Stelle.

## Bericht

Administratoren sehen unter **System → Assets Report**, ob Seiten für Optimierung, Early Hints und den statischen Cache bereit sind.

## Automatische Bildgrößen

Die Extension misst im Browser, wie breit ein Bild im jeweiligen Inhaltselement wirklich dargestellt wird (Hero, Text/Medien links/rechts, Text/Medien oben usw.). Daraus erzeugt sie `sizes` und `srcset`, sodass jedes Element nur die Bildgröße lädt, die es braucht.

- Optional (Entwickler): Mit `slot="textmedia-left"` am ViewHelper teilen sich alle Elemente mit demselben Namen ein gelerntes Profil. Nur verwenden, wenn das Layout wirklich identisch ist (gleiche Spalte/Container). Ändert sich das Profil, werden alle Seiten neu erzeugt, auf denen der Slot gemessen wurde.
- `<mai:picture.source>` übernimmt ebenfalls die gemessenen Breiten (die `media`-Bedingung bleibt). Mit `measured="false"` bleiben die angegebenen Werte.
- Bis genug Messwerte da sind (mindestens 3 Seitenaufrufe pro Viewport-Bereich), gelten die im Template angegebenen Breakpoints.
- Gelernt wird pro Inhaltselement und Bild. Ändert sich Layout, Typ, Spalte oder Bild eines Elements im Backend, werden dessen Messwerte verworfen und neu gelernt.
- Die Bildvarianten entstehen beim ersten Rendern der Seite. Mit `vendor/bin/typo3 maispace:assets:image-measure:warmup` lassen sie sich vorab erzeugen.
- Messwerte zurücksetzen: `maispace:assets:image-measure:reset --all` oder `... reset 123` (Inhaltselement 123). Die betroffenen Seiten werden dabei neu erzeugt und nutzen bis zum neuen Lernen wieder die Template-Breakpoints.
- Abschalten: Extension-Konfiguration `enableImageMeasurement`.

## Aufräumen

- Alte kompilierte CSS/JS-Dateien löschen: `vendor/bin/typo3 maispace:assets:compiled:prune --older-than=2592000` (30 Tage; ohne Option gilt `processingCacheLifetime`). Danach werden Seiten-Cache und statische Dateien geleert.
- Statische HTML-Dateien folgen dem Seiten-Cache: Was den Seiten-Cache leert (Speichern im Backend, „Frontend-Caches leeren“, `cache:flush`, Ablauf durch Start-/Stoppzeit), macht auch die statische Datei ungültig.

## Hinweise für Entwickler

- `BeforeAssetInjectionEvent` läuft nur noch dort, wo Inhalt direkt ins HTML eingefügt wird (kritisches CSS inline, minifiziertes JavaScript). Extern eingebundene Stylesheets durchlaufen es nicht mehr.
- Voraussetzung ist TYPO3 14 (die Listener nutzen `AfterCacheableContentIsGeneratedEvent::getContent()`, der statische Cache `frontend.page.parts`; beides gibt es erst ab 14).
