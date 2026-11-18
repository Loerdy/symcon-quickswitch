# QuickSwitch

Kachel für die Kachel-Visualisierung von IP-Symcon, über die verknüpfte Aktoren per Antippen geschaltet werden.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

- Beliebig viele Aktoren (Boolean-Variablen mit Aktion) in einer Kachel.
- Pro Aktor ein Symcon-Icon oder eigene Bilder (Medienobjekte):
  - **Icon:** bei An in der gewählten Farbe bzw. der Akzentfarbe des Designs, bei Aus grau.
  - **Nur An-Bild:** bei An im Original, bei Aus in Graustufen.
  - **An- und Aus-Bild:** je nach Zustand das passende Bild.
- Optionale Beschriftung unter der Grafik.
- Antippen schaltet den Aktor um (über die Aktion der Variable, wie in der Visualisierung).
- Optionaler Button **Alle an/aus**, immer an erster Stelle: Ist mindestens ein berücksichtigter Aktor an, werden alle berücksichtigten Aktoren ausgeschaltet, sonst eingeschaltet. Pro Aktor einstellbar, ob er berücksichtigt wird.
- Icons und Bilder skalieren mit der Größe der Kachel; die Anordnung wird automatisch so gewählt, dass die Schaltflächen möglichst groß sind.
- Zustandsänderungen von außen (z. B. Schalter an der Wand) werden sofort angezeigt, ohne Polling.

### 2. Voraussetzungen

- IP-Symcon ab Version 9.1
- Kachel-Visualisierung

### 3. Software-Installation

Über das Module Control folgende URL hinzufügen:

```
https://github.com/Loerdy/symcon-quickswitch
```

### 4. Einrichten der Instanzen in IP-Symcon

Unter „Instanz hinzufügen“ ist das Modul **QuickSwitch** (Suche auch nach „Schnellschalter“) zu finden.

__Konfigurationsseite__:

| Name | Beschreibung |
| --- | --- |
| Aktoren | Liste der zu schaltenden Aktoren, Reihenfolge per Ziehen änderbar |
| ↳ Variable | Boolean-Variable mit Aktion (z. B. Status eines Schaltaktors) |
| ↳ Beschriftung | Text unter der Grafik; leer = Name des Geräts (übergeordnete Instanz) bzw. der Variable |
| ↳ Beschriftung anzeigen | Blendet die Beschriftung ein oder aus (als Tooltip bleibt sie erhalten) |
| ↳ Icon | Symcon-Icon, falls kein An-Bild gewählt ist |
| ↳ Iconfarbe bei An | Farbe des Icons im Zustand An; leer = Akzentfarbe des Designs |
| ↳ Bild An | Optionales Medienobjekt (JPEG/PNG, max. 512 KB); ersetzt das Icon |
| ↳ Bild Aus | Optionales Medienobjekt für den Zustand Aus; ohne Aus-Bild wird das An-Bild grau dargestellt |
| ↳ Bei Alle an/aus schalten | Ob der Aktor vom Button Alle an/aus geschaltet wird |
| Button Alle an/aus anzeigen | Zeigt den Button an erster Stelle der Kachel |
| Beschriftung | Eigene Beschriftung des Buttons; leer = „Alle an“ bzw. „Alle aus“ je nach Zustand |
| Icon | Icon des Buttons |

Ist ein Eintrag ungültig (Variable gelöscht, kein Boolean oder ohne Aktion), zeigt die Instanz einen Fehlerstatus. Der Eintrag wird in der Kachel deaktiviert dargestellt; die übrigen Einträge bleiben bedienbar.

### 5. Statusvariablen und Profile

Das Modul legt keine Statusvariablen und keine Profile an. Die verknüpften Variablen werden nur gelesen und über ihre Aktion geschaltet; die eigenen Bilder werden nur gelesen.

### 6. Visualisierung

Die Instanz wird in der Kachel-Visualisierung als eigene Kachel (HTML-SDK) dargestellt. Farben, Schrift und hell/dunkel richten sich nach dem gewählten Design der Visualisierung.

### 7. PHP-Befehlsreferenz

Das Modul stellt keine eigenen öffentlichen Funktionen bereit. Die Bedienung der Kachel ist auch per Skript möglich:

```php
// Aktor mit der Variable 12345 umschalten (nur verknüpfte Variablen)
IPS_RequestAction($InstanzID, 'Toggle', 12345);

// Alle an/aus auslösen (nur wenn der Button aktiviert ist)
IPS_RequestAction($InstanzID, 'All', true);
```
