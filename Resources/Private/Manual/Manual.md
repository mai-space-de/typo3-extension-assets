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
