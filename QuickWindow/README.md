# QuickWindow

Kachel für die Kachel-Visualisierung von IP-Symcon, die den Zustand von Fenstern und Türen anzeigt. Grundlage ist je Öffnung ein Reed-Kontakt; zusätzlich können Fenstergriffe mit Griffstellung und Verriegelungsmotor (z. B. Siegenia über Matter) zugeordnet und über ein Menü verriegelt bzw. geöffnet werden.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

- Beliebig viele Fenster und Türen in einer Kachel, je Öffnung ein Button mit frei wählbarer Beschriftung.
- Gesamtzustand je Öffnung aus Reed-Kontakt und Griffen; angezeigt wird der auffälligste Zustand:

  | Zustand | Bedingung | Darstellung |
  | --- | --- | --- |
  | Gewaltsam geöffnet | ein Griff meldet gewaltsam geöffnet | Warnsymbol, rot |
  | Offen | ein Griff meldet geöffnet oder der Kontakt ist offen | Icon „offen“, orange hinterlegt |
  | Gekippt | ein Griff meldet gekippt | Icon „gekippt“, blau hinterlegt |
  | Störung | ein Griff meldet blockiert, unbekannten Fehler bzw. einen unbekannten Wert oder nicht vollständig verriegelt | Warnsymbol, rot |
  | Geschlossen | Kontakt zu, alle Griffe geschlossen | Icon „geschlossen“, neutral |
  | Verriegelt | wie geschlossen und alle Griffe mit Motor verriegelt | Icon „geschlossen“ in der Akzentfarbe mit Schloss-Symbol |

- Optional der Zustand als Text unter dem Button und eine Zusammenfassung über den Buttons (z. B. „2 offen · 1 gekippt“ bzw. „Alles geschlossen“).
- **Griff-Menü:** Öffnungen mit Griffen erhalten einen kleinen Pfeil; Antippen öffnet ein Menü mit einer Zeile je Griff (Griffstellung, Verriegelung) und dem passenden Befehl:
  - Verriegelt: **Öffnen** (optional mit Rückfrage).
  - Nicht verriegelt: **Verriegeln**, aber nur, solange der Griff „Geschlossen“ meldet. Ein offener oder gekippter Flügel wird nie verriegelt; das prüft auch das Modul selbst.
  - Bei mehreren Griffen mit Motor zusätzlich **Alle verriegeln**. Es verriegelt alle geschlossenen, noch nicht verriegelten Griffe; ein Fehler bei einem Griff stoppt die übrigen nicht.
- Nach einem Befehl pulsiert der Button, bis der Griff einen neuen Verriegelungsstatus meldet (höchstens 30 Sekunden). Schlägt der Befehl fehl, werden Button und Zeile kurz rot umrandet und eine Meldung ins Meldungsfenster geschrieben.
- Öffnungen ohne Griffe sind reine Anzeige.
- Zustandsänderungen werden sofort angezeigt, ohne Polling; ein offenes Menü aktualisiert sich mit.

### 2. Voraussetzungen

- IP-Symcon ab Version 9.1
- Kachel-Visualisierung
- Je Öffnung eine Boolean-Variable des Reed-Kontakts
- Optional je Griff: Integer-Variable der Griffstellung sowie Integer-Status- und -Aktionsvariable der Verriegelung (Matter DoorLock)

### 3. Software-Installation

Über das Module Control folgende URL hinzufügen:

```
https://github.com/Loerdy/symcon-quickswitch
```

### 4. Einrichten der Instanzen in IP-Symcon

Unter „Instanz hinzufügen“ ist das Modul **QuickWindow** (Suche auch nach „Schnellfenster“) zu finden.

__Konfigurationsseite__:

| Name | Beschreibung |
| --- | --- |
| Fenster und Türen | Liste der Öffnungen, Reihenfolge per Ziehen änderbar |
| ↳ Kontaktvariable | Boolean-Variable des Reed-Kontakts, true = offen |
| ↳ Invertiert | Für Kontakte, die true = geschlossen melden (z. B. viele Matter-Kontaktsensoren) |
| ↳ Typ | Fenster oder Tür; bestimmt die Standard-Icons |
| ↳ Beschriftung | Text unter dem Button; leer = Name des Geräts (übergeordnete Instanz der Kontaktvariable) |
| ↳ Beschriftung anzeigen | Blendet die Beschriftung ein oder aus (als Tooltip bleibt sie erhalten) |
| ↳ Icon geschlossen / offen / gekippt | Eigene Icons; leer = Standard-Icon des Typs. Gekippt ohne eigenes Icon nutzt das eigene Icon für offen |
| Fenstergriffe | Liste der Griffe für das Pfeil-Menü; Reihenfolge = Reihenfolge im Menü |
| ↳ Fenster/Tür | Kontaktvariable einer Öffnung aus der Liste oben, zu der der Griff gehört |
| ↳ Griffstellung | Integer-Variable, bei Matter `DoorLock_DoorState` („Türstatus“) |
| ↳ Verriegelungsstatus | Optional, Integer-Variable, bei Matter `DoorLock_LockState` |
| ↳ Verriegelung Aktion | Optional, Integer-Variable mit Aktion, bei Matter `DoorLock_LockUnlockUnbolt` |
| ↳ Beschriftung | Text im Menü (z. B. „Flügel links“); leer = Name des Geräts (übergeordnete Instanz der Griffstellung) |
| ↳ Öffnen bestätigen | Öffnen erst nach Rückfrage ausführen (Standard: aus) |
| Zustand als Text | Zeigt den Zustand als zweite Zeile unter dem Button |
| Zusammenfassung | Zeigt die Zusammenfassung über den Buttons |

Für Griffe ohne Motor bleiben beide Verriegelungsvariablen leer. Ist nur eine der beiden gesetzt, ist der Eintrag ungültig.

__Werte der Variablen__ (Matter DoorLock, z. B. Siegenia-Fenstergriff):

| Griffstellung | Bedeutung | Verriegelungsstatus | Bedeutung | Aktion | Befehl |
| --- | --- | --- | --- | --- | --- |
| 0 | Geöffnet | 0 | Nicht vollständig verriegelt | 0 | Öffnen |
| 1 | Geschlossen | 1 | Verriegelt | 2 | Verriegeln |
| 2 | Blockiert | 2 | Entriegelt | | |
| 3 | Gewaltsam geöffnet | 3 | Entsperrt | | |
| 4 | Unbekannter Fehler | | | | |
| 5 | Gekippt | | | | |

Die Texte im Menü stammen aus den Darstellungen der Griffstellung bzw. des Verriegelungsstatus (formatierter Wert), Beschriftung, Icon und Farbe der Befehle aus der Darstellung der Aktionsvariable. Ohne Darstellung verwendet das Modul eigene Texte.

Ist ein Eintrag ungültig (Variable gelöscht oder falscher Typ, Griff ohne gültige Öffnung, Aktionsvariable ohne Aktion), zeigt die Instanz einen Fehlerstatus. Der Eintrag wird deaktiviert dargestellt; die übrigen bleiben nutzbar.

### 5. Statusvariablen und Profile

Das Modul legt keine Statusvariablen und keine Profile an. Kontakte, Griffstellungen und Verriegelungsstatus werden nur gelesen; bedient wird ausschließlich über die Aktion der Aktionsvariable.

### 6. Visualisierung

Die Instanz wird in der Kachel-Visualisierung als eigene Kachel (HTML-SDK) dargestellt. Farben, Schrift und hell/dunkel richten sich nach dem gewählten Design der Visualisierung.

### 7. PHP-Befehlsreferenz

Das Modul stellt keine eigenen öffentlichen Funktionen bereit. Die Bedienung ist auch per Skript möglich; die Rückfrage gilt nur in der Kachel.

```php
// Griff mit der Aktionsvariable 12345 verriegeln (nur wenn der Griff geschlossen meldet) bzw. öffnen
IPS_RequestAction($InstanzID, 'Lock', 12345);
IPS_RequestAction($InstanzID, 'Open', 12345);

// Alle geschlossenen Griffe der Öffnung mit der Kontaktvariable 23456 verriegeln
IPS_RequestAction($InstanzID, 'LockAll', 23456);
```

Befehle werden nur für Griffe ausgeführt, die dieser Instanz zugeordnet sind.
