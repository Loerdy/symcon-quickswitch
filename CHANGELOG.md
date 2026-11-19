# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Hinzugefügt

- Modul **QuickSwitch**: Kachel mit beliebig vielen Aktoren (Boolean-Variablen mit Aktion), die per Antippen umgeschaltet werden.
- Grafik pro Aktor: Symcon-Icon (bei An in eigener Farbe bzw. Akzentfarbe, bei Aus grau) oder eigene Bilder als Medienobjekte (nur An-Bild: bei Aus in Graustufen; optional eigenes Aus-Bild).
- Optionale Beschriftung unter der Grafik.
- Optionaler Button **Alle an/aus**, immer an erster Stelle; pro Aktor einstellbar, ob er berücksichtigt wird.
- Automatische Anordnung und Skalierung der Schaltflächen passend zur Kachelgröße.
- QuickSwitch: Zusatzvariablen je Aktor mit Pfeil-Menü: Umschalter (Boolean), Buttons (Aufzählung) und Schieberegler (Schieberegler bzw. Profil mit Wertebereich), mit Live-Aktualisierung.
- Modul **QuickLock**: Kachel mit beliebig vielen Türschlössern (getrennte Status- und Aktionsvariable, z. B. Nuki über Matter).
- QuickLock: Anzeige des Verriegelungsstatus per Icon und Farbe, optional als Text; Befehle, Beschriftungen, Icons und Farben aus der Darstellung der Aktionsvariable.
- QuickLock: Antippen verriegelt bzw. schließt auf; Schlösser mit Öffnen und Entriegeln erhalten einen Pfeil mit allen Befehlen; Rückfrage für Öffnen und Entriegeln pro Schloss einstellbar.
- QuickLock: Anzeige für laufende Befehle bis zur Rückmeldung des Schlosses sowie Hinweis bei fehlgeschlagenen Befehlen.
- QuickLock: Eigene Icons je Zustand (leer = Icon aus der Darstellung).
- QuickLock: Eigene Bilder (Medienobjekte) für verriegelt, entriegelt und entsperrt; Bilder werden nur bei Konfigurations- oder Medienänderungen übertragen, nicht bei jeder Statusänderung.
