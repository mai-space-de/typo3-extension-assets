---
version: "1.0"
date: 2026-11-06
---

# Handbuch: Assets

Styles, Skripte, Schriften, SVG-Sprites und responsive Bilder laufen über diese Extension. SCSS wird serverseitig kompiliert, CSS und JavaScript können minifiziert werden.

## Was Redaktion beachten sollte

Bilder in Inhaltselementen werden responsive ausgeliefert. Ein SVG-Sprite bündelt die Icons des Themes.

Subresource Integrity setzt Prüfsummen auf lokale Dateien. Für die Look-Vorschau darf die Prüfsumme mit dem Wert `none` ausgeschaltet werden, sonst bleibt die Vorschau ohne Styles.

## Bericht

Administratoren sehen unter **System → Assets Report**, ob Seiten für Optimierung, Early Hints und den statischen Cache bereit sind.

## Automatische Bildgrößen

Die Extension misst im Browser, wie breit ein Bild im jeweiligen Inhaltselement wirklich dargestellt wird (Hero, Text/Medien links/rechts, Text/Medien oben usw.). Daraus erzeugt sie `sizes` und `srcset`, sodass jedes Element nur die Bildgröße lädt, die es braucht.

- Bis genug Messwerte da sind (mindestens 3 Seitenaufrufe pro Viewport-Bereich), gelten die im Template angegebenen Breakpoints.
- Gelernt wird pro Inhaltselement und Bild. Ändert sich Layout, Typ, Spalte oder Bild eines Elements im Backend, werden dessen Messwerte verworfen und neu gelernt.
- Die Bildvarianten entstehen beim ersten Rendern der Seite. Mit `vendor/bin/typo3 maispace:assets:image-measure:warmup` lassen sie sich vorab erzeugen.
- Messwerte zurücksetzen: `maispace:assets:image-measure:reset --all` oder `... reset 123` (Inhaltselement 123).
- Abschalten: Extension-Konfiguration `enableImageMeasurement`.
