# QuickWindow

Kachel für die Kachel-Visualisierung von IP-Symcon, die den Zustand von Fenstern und Türen anzeigt. Grundlage ist je Öffnung ein Reed-Kontakt; zusätzlich können Fenstergriffe (Siegenia über Matter, Homematic-Drehgriffe) und Schlösser (Verriegelungsmotor der Griffe, Türschlösser wie Nuki) zugeordnet und direkt in der Kachel verriegelt bzw. aufgeschlossen werden.

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
- Zustand je Öffnung: maßgeblich ist der Reed-Kontakt. Nur bei offenem Kontakt präzisieren die Griffe den Zustand:

  | Zustand | Bedingung | Darstellung |
  | --- | --- | --- |
  | Geschlossen | Kontakt geschlossen (unabhängig von Griffen und Schlössern) | Icon „geschlossen“, grün hinterlegt |
  | Gewaltsam geöffnet | Kontakt offen, ein Griff meldet gewaltsam geöffnet | Warnsymbol, rot |
  | Gekippt | Kontakt offen, ein Griff meldet gekippt, keiner offen | Icon „gekippt“, blau hinterlegt |
  | Offen | Kontakt offen, sonst | Icon „offen“, orange hinterlegt |

  Schlösser färben die Öffnung nicht, sondern nur ihren eigenen Button in der Leiste. Störungen eines Griffs zeigt dessen Griffanzeige.

- Optional der Zustand als Text unter dem Button und eine Zusammenfassung über den Buttons (z. B. „2 offen · 1 gekippt“ bzw. „Alles geschlossen“).
- **Leiste im Button:** Je Griff bzw. Schloss erscheint unten in der Fläche
  - der **Griff** als Pfeil in seiner Richtung: unten = geschlossen, rechts = offen, oben = gekippt, Warnsymbol bei Störung;
  - das **Schloss** als Button, grün verriegelt, rot entriegelt. Antippen schließt auf (Entriegeln, wenn das Schloss es anbietet, sonst Öffnen; optional mit Rückfrage) bzw. verriegelt. Verriegeln geht nur, solange der Griff „geschlossen“ meldet; Schlösser ohne Griff (z. B. Nuki an der Haustür) nur bei geschlossenem Kontakt. Das prüft auch das Modul selbst.
- **Menü:** Antippen des Buttons öffnet ein Menü mit einer Zeile je Griff bzw. Schloss (Griffstellung, Verriegelung, Befehle), bei Schlössern mit Entriegeln und Öffnen zusätzlich **Öffnen** (Falle ziehen). Bei mehreren Schlössern gibt es **Alle verriegeln**. Es verriegelt alle verriegelbaren, noch nicht verriegelten Schlösser; ein Fehler bei einem Schloss stoppt die übrigen nicht.
- Nach einem Befehl pulsiert der Schloss-Button, bis das Schloss einen neuen Status meldet (höchstens 30 Sekunden). Schlägt der Befehl fehl, wird er kurz rot umrandet und eine Meldung ins Meldungsfenster geschrieben.
- Die Schaltflächen nutzen die Kachel möglichst vollständig; in schmalen, hohen Kacheln werden sie bis doppelt so hoch wie breit. Hat eine Schaltfläche eine Leiste, ist in allen Platz dafür reserviert, damit die Icons gleich groß und gleich ausgerichtet sind.
- Öffnungen ohne Griffe und Schlösser sind reine Anzeige.
- Zustandsänderungen werden sofort angezeigt, ohne Polling; ein offenes Menü aktualisiert sich mit.

### 2. Voraussetzungen

- IP-Symcon ab Version 9.1
- Kachel-Visualisierung
- Je Öffnung eine Boolean-Variable des Reed-Kontakts
- Optional je Griff bzw. Schloss: Integer-Variable der Griffstellung und/oder Integer-Status- und -Aktionsvariable der Verriegelung (Matter DoorLock)

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
| Griffe und Schlösser | Liste der Griffe und Schlösser; Reihenfolge = Reihenfolge in der Leiste und im Menü |
| ↳ Fenster/Tür | Kontaktvariable einer Öffnung aus der Liste oben, zu der der Eintrag gehört |
| ↳ Griffart | Matter Türstatus (z. B. Siegenia) oder Homematic Drehgriff; bestimmt die Bedeutung der Griffstellung |
| ↳ Griffstellung | Optional, Integer-Variable; bei Matter `DoorLock_DoorState` („Türstatus“), bei Homematic `STATE` |
| ↳ Verriegelungsstatus | Optional, Integer-Variable, bei Matter `DoorLock_LockState` |
| ↳ Verriegelung Aktion | Optional, Integer-Variable mit Aktion, bei Matter `DoorLock_LockUnlockUnbolt` |
| ↳ Beschriftung | Text im Menü und Tooltip (z. B. „Flügel links“); leer = Name des Geräts (übergeordnete Instanz) |
| ↳ Aufschließen bestätigen | Aufschließen bzw. Öffnen erst nach Rückfrage ausführen (Standard: aus; für Haustüren empfohlen) |
| Zustand als Text | Zeigt den Zustand als zweite Zeile unter dem Button |
| Zusammenfassung | Zeigt die Zusammenfassung über den Buttons |

Jeder Eintrag braucht eine Griffstellung, ein Schloss oder beides:

| Gerät | Griffstellung | Verriegelungsstatus und Aktion |
| --- | --- | --- |
| Siegenia-Griff mit Motor | `DoorLock_DoorState` | `DoorLock_LockState`, `DoorLock_LockUnlockUnbolt` |
| Homematic-Drehgriff | `STATE`, Griffart Homematic | leer |
| Türschloss (z. B. Nuki) | leer | `DoorLock_LockState`, `DoorLock_LockUnlockUnbolt` |

Ist nur eine der beiden Verriegelungsvariablen gesetzt, ist der Eintrag ungültig.

__Werte der Variablen__:

| Wert | Griffstellung Matter | Griffstellung Homematic | Verriegelungsstatus | Aktion |
| --- | --- | --- | --- | --- |
| 0 | Geöffnet | Geschlossen | Nicht vollständig verriegelt | Öffnen |
| 1 | Geschlossen | Gekippt | Verriegelt | Entriegeln (nur Schlösser mit Knauf) |
| 2 | Blockiert | Offen | Entriegelt | Verriegeln |
| 3 | Gewaltsam geöffnet | | Entsperrt | |
| 4 | Unbekannter Fehler | | | |
| 5 | Gekippt | | | |

Andere Werte gelten als Störung. Welche Befehle ein Schloss anbietet, liest das Modul aus der Darstellung der Aktionsvariable.

Die Texte im Menü stammen aus den Darstellungen der Griffstellung bzw. des Verriegelungsstatus (formatierter Wert), Beschriftung, Icon und Farbe der Befehle aus der Darstellung der Aktionsvariable. Ohne Darstellung verwendet das Modul eigene Texte.

Ist ein Eintrag ungültig (Variable gelöscht oder falscher Typ, Griff ohne gültige Öffnung, Aktionsvariable ohne Aktion), zeigt die Instanz einen Fehlerstatus. Der Eintrag wird deaktiviert dargestellt; die übrigen bleiben nutzbar.

### 5. Statusvariablen und Profile

Das Modul legt keine Statusvariablen und keine Profile an. Kontakte, Griffstellungen und Verriegelungsstatus werden nur gelesen; bedient wird ausschließlich über die Aktion der Aktionsvariable.

### 6. Visualisierung

Die Instanz wird in der Kachel-Visualisierung als eigene Kachel (HTML-SDK) dargestellt. Farben, Schrift und hell/dunkel richten sich nach dem gewählten Design der Visualisierung.

### 7. PHP-Befehlsreferenz

Das Modul stellt keine eigenen öffentlichen Funktionen bereit. Die Bedienung ist auch per Skript möglich; die Rückfrage gilt nur in der Kachel.

```php
// Schloss mit der Aktionsvariable 12345 verriegeln (nur bei geschlossenem Griff bzw. Kontakt), entriegeln bzw. öffnen
IPS_RequestAction($InstanzID, 'Lock', 12345);
IPS_RequestAction($InstanzID, 'Unlock', 12345);
IPS_RequestAction($InstanzID, 'Open', 12345);

// Wie der Schloss-Button: verriegelt => aufschließen, sonst verriegeln
IPS_RequestAction($InstanzID, 'Toggle', 12345);

// Alle verriegelbaren Schlösser der Öffnung mit der Kontaktvariable 23456 verriegeln
IPS_RequestAction($InstanzID, 'LockAll', 23456);
```

Befehle werden nur für Schlösser ausgeführt, die dieser Instanz zugeordnet sind, und nur, wenn das Schloss sie anbietet.
