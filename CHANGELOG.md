# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

## [1.0.0] - 2026-11-20

### Hinzugefügt

- Modul **QuickSwitch**: Kachel mit beliebig vielen Aktoren (Boolean-Variablen mit Aktion), die per Antippen umgeschaltet werden.
- Grafik pro Aktor: Symcon-Icon (bei An in eigener Farbe bzw. Akzentfarbe, bei Aus grau) oder eigene Bilder als Medienobjekte (nur An-Bild: bei Aus in Graustufen; optional eigenes Aus-Bild).
- Optionale Beschriftung unter der Grafik.
- Optionaler Button **Alle an/aus**, immer an erster Stelle; pro Aktor einstellbar, ob er berücksichtigt wird.
- Automatische Anordnung und Skalierung der Schaltflächen passend zur Kachelgröße.
- QuickSwitch: Zusatzvariablen je Aktor mit Pfeil-Menü: Umschalter (Boolean), Buttons (Aufzählung) und Schieberegler (Schieberegler bzw. Profil mit Wertebereich), mit Live-Aktualisierung.
- QuickSwitch: Zusatzvariablen vom Typ String: Buttons bei Aufzählung (z. B. Szenen), sonst Textfeld; Werte außerhalb der Aufzählung werden ohne Text und ohne Markierung angezeigt.
- QuickSwitch: Zusatzvariablen mit Darstellung Farbe (RGB): Farbtupfer aus den Standardwerten der Darstellung und Farbton-Regler.
- QuickSwitch: Farbe und Schieberegler zeigen einen gewählten Wert sofort an, bis die Variable ihn bestätigt (höchstens 10 Sekunden, danach wieder der tatsächliche Wert).
- Modul **QuickLock**: Kachel mit beliebig vielen Türschlössern (getrennte Status- und Aktionsvariable, z. B. Nuki über Matter).
- QuickLock: Anzeige des Verriegelungsstatus per Icon und Farbe, optional als Text; Befehle, Beschriftungen, Icons und Farben aus der Darstellung der Aktionsvariable.
- QuickLock: Antippen verriegelt bzw. schließt auf; Schlösser mit Öffnen und Entriegeln erhalten einen Pfeil mit allen Befehlen; Rückfrage für Öffnen und Entriegeln pro Schloss einstellbar.
- QuickLock: Anzeige für laufende Befehle bis zur Rückmeldung des Schlosses sowie Hinweis bei fehlgeschlagenen Befehlen.
- QuickLock: Eigene Icons je Zustand (leer = Icon aus der Darstellung).
- QuickLock: Eigene Bilder (Medienobjekte) für verriegelt, entriegelt und entsperrt; Bilder werden nur bei Konfigurations- oder Medienänderungen übertragen, nicht bei jeder Statusänderung.
- Modul **QuickWindow**: Kachel mit beliebig vielen Fenstern und Türen; Zustand nach dem Reed-Kontakt (optional invertiert): geschlossen (grau, Normalzustand) bzw. offen (eingefärbt); bei offenem Kontakt präzisieren zugeordnete Griffe den Zustand (gekippt, gewaltsam geöffnet).
- QuickWindow: Griffarten Matter-Türstatus (z. B. Siegenia) und Homematic-Drehgriff (0 = geschlossen, 1 = gekippt, 2 = offen); Türschlösser ohne Griff (z. B. Nuki) zuordenbar.
- QuickWindow: Leiste unten im Button je Griff bzw. Schloss: Griffanzeige als Pfeil in Griffrichtung und Schloss-Button zum direkten Verriegeln bzw. Aufschließen (optional mit Rückfrage); Antippen des Buttons öffnet ein Menü mit allen Details und „Alle verriegeln“.
- QuickWindow: Schlösser färben nur ihren eigenen Button (grau verriegelt, rot entriegelt), nicht die Öffnung. Verriegeln nur bei geschlossenem Griff bzw. bei Schlössern ohne Griff bei geschlossenem Kontakt.
- QuickLock: Verriegelt wird als Normalzustand grau dargestellt (wie Aus bei QuickSwitch); farbig hinterlegt sind nur entriegelt, entsperrt und nicht vollständig verriegelt.
- QuickWindow: Icons sind in allen Buttons gleich groß und gleich ausgerichtet, auch wenn nur einige eine Leiste haben.
- QuickWindow: Schaltflächen nutzen in schmalen, hohen Kacheln die verfügbare Höhe (bis doppelt so hoch wie breit).
- QuickWindow: Optionale Zusammenfassung über den Buttons (z. B. „2 offen · 1 gekippt“) und Zustand als Text unter dem Button.
- Alle Module: Meldung im Meldungsfenster, wenn die Kachel-Vorlage nicht geladen werden kann.
