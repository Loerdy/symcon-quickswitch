# QuickLock

Kachel für die Kachel-Visualisierung von IP-Symcon, die den Zustand von Türschlössern anzeigt und sie per Antippen verriegelt bzw. aufschließt. Ausgelegt für Schlösser, die über Matter eingebunden sind (z. B. Nuki), mit getrennter Status- und Aktionsvariable.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

- Beliebig viele Schlösser in einer Kachel, je Schloss ein Button.
- Anzeige des Verriegelungsstatus durch Icon und Farbe, optional zusätzlich als Text unter dem Button:

  | Status | Darstellung |
  | --- | --- |
  | Verriegelt | Icon und Farbe des Befehls „Verriegeln“ |
  | Entriegelt | Icon und Farbe des Befehls „Entriegeln“ (ohne diesen: „Öffnen“), Fläche farbig hinterlegt |
  | Entsperrt (Falle gezogen) | Icon und Farbe des Befehls „Öffnen“, Fläche farbig hinterlegt |
  | Nicht vollständig verriegelt | Warnsymbol in Rot |

- **Eigene Bilder** (Medienobjekte) für verriegelt, entriegelt und entsperrt; sie ersetzen das Icon im jeweiligen Zustand. Ohne Bild für entsperrt wird das Bild für entriegelt verwendet, ohne Bild das Icon.
- **Antippen:** Ist das Schloss verriegelt, wird es aufgeschlossen (Entriegeln, bei Schlössern ohne diesen Befehl Öffnen); sonst wird es verriegelt.
- **Pfeil:** Schlösser, die Öffnen und Entriegeln getrennt anbieten (z. B. Tür mit Knauf), erhalten einen kleinen Pfeil. Er öffnet ein Menü mit allen Befehlen.
- **Rückfrage:** Pro Schloss einstellbar, ob Öffnen bzw. Entriegeln erst nach einer Bestätigung ausgeführt wird.
- Nach einem Befehl pulsiert das Icon, bis das Schloss einen neuen Status meldet (höchstens 30 Sekunden). Schlägt der Befehl fehl, wird der Button kurz rot umrandet und eine Meldung ins Meldungsfenster geschrieben.
- Zustandsänderungen von außen (App, Taster, Automatik) werden sofort angezeigt, ohne Polling.

### 2. Voraussetzungen

- IP-Symcon ab Version 9.1
- Kachel-Visualisierung
- Schloss mit einer Integer-Statusvariable und einer Integer-Aktionsvariable (z. B. Matter DoorLock)

### 3. Software-Installation

Über das Module Control folgende URL hinzufügen:

```
https://github.com/Loerdy/symcon-quickswitch
```

### 4. Einrichten der Instanzen in IP-Symcon

Unter „Instanz hinzufügen“ ist das Modul **QuickLock** (Suche auch nach „Schnellschloss“) zu finden.

__Konfigurationsseite__:

| Name | Beschreibung |
| --- | --- |
| Schlösser | Liste der Schlösser, Reihenfolge per Ziehen änderbar |
| ↳ Statusvariable | Integer-Variable mit dem Verriegelungsstatus, bei Matter `DoorLock_LockState` („Verriegelungsstatus“) |
| ↳ Aktionsvariable | Integer-Variable mit Aktion, bei Matter `DoorLock_LockUnlockUnbolt` („Türschloss“) |
| ↳ Beschriftung | Text unter dem Button; leer = Name des Geräts (übergeordnete Instanz der Aktionsvariable) |
| ↳ Beschriftung anzeigen | Blendet die Beschriftung ein oder aus (als Tooltip bleibt sie erhalten) |
| ↳ Bild verriegelt | Optionales Medienobjekt (JPEG/PNG, max. 512 KB); ersetzt das Icon im Zustand verriegelt |
| ↳ Bild entriegelt | Optionales Medienobjekt für den Zustand entriegelt |
| ↳ Bild entsperrt | Optionales Medienobjekt für den Zustand entsperrt; ohne Bild wird das Bild für entriegelt verwendet |
| ↳ Öffnen bestätigen | Öffnen erst nach Rückfrage ausführen (Standard: an) |
| ↳ Entriegeln bestätigen | Entriegeln erst nach Rückfrage ausführen (Standard: aus) |
| Verriegelungsstatus als Text | Zeigt den Status als zweite Zeile unter dem Button |

__Werte der Variablen__ (Matter DoorLock):

| Statusvariable | Bedeutung | Aktionsvariable | Befehl |
| --- | --- | --- | --- |
| 0 | Nicht vollständig verriegelt | 0 | Öffnen (bei Schlössern mit Entriegeln: Falle ziehen) |
| 1 | Verriegelt | 1 | Entriegeln (aufschließen ohne Falle zu ziehen) |
| 2 | Entriegelt | 2 | Verriegeln |
| 3 | Entsperrt | | |

Welche Befehle ein Schloss anbietet, liest das Modul aus der Darstellung (Aufzählung) bzw. dem Profil der Aktionsvariable. Bietet sie den Wert 1 an, gibt es den Pfeil mit allen Befehlen. Beschriftungen, Icons und Farben der Befehle werden ebenfalls von dort übernommen. Ist keine Darstellung lesbar, gelten nur Öffnen und Verriegeln.

Den Statustext liefert die Darstellung der Statusvariable (formatierter Wert). Ohne Darstellung verwendet das Modul eigene Texte.

Ist ein Eintrag ungültig (Variable gelöscht, kein Integer oder Aktionsvariable ohne Aktion), zeigt die Instanz einen Fehlerstatus. Der Eintrag wird in der Kachel deaktiviert dargestellt; die übrigen Schlösser bleiben bedienbar.

Änderungen an der Darstellung der Aktionsvariable werden übernommen, sobald sich der Status ändert, die Kachel neu geöffnet oder die Instanz übernommen wird.

### 5. Statusvariablen und Profile

Das Modul legt keine Statusvariablen und keine Profile an. Die Statusvariable und die eigenen Bilder werden nur gelesen; bedient wird ausschließlich über die Aktion der Aktionsvariable.

Bei *Nicht vollständig verriegelt* erscheint immer das rote Warnsymbol, auch wenn eigene Bilder hinterlegt sind.

### 6. Visualisierung

Die Instanz wird in der Kachel-Visualisierung als eigene Kachel (HTML-SDK) dargestellt. Farben, Schrift und hell/dunkel richten sich nach dem gewählten Design der Visualisierung.

### 7. PHP-Befehlsreferenz

Das Modul stellt keine eigenen öffentlichen Funktionen bereit. Die Bedienung ist auch per Skript möglich; der Wert ist jeweils die ID der Aktionsvariable. Die Rückfrage gilt nur in der Kachel.

```php
// Schloss mit der Aktionsvariable 12345 verriegeln, entriegeln bzw. öffnen
IPS_RequestAction($InstanzID, 'Lock', 12345);
IPS_RequestAction($InstanzID, 'Unlock', 12345);
IPS_RequestAction($InstanzID, 'Open', 12345);

// Wie Antippen: verriegelt => aufschließen, sonst verriegeln
IPS_RequestAction($InstanzID, 'Toggle', 12345);
```

Befehle werden nur für verknüpfte Schlösser ausgeführt und nur, wenn das Schloss sie anbietet.