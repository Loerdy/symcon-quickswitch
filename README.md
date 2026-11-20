# symcon-quickswitch

IP-Symcon-Library **QuickSwitch**: Kacheln für die Kachel-Visualisierung, in denen verknüpfte Geräte per Antippen bedient werden – Aktoren mit Symcon-Icons oder eigenen Bildern und optionalem Button „Alle an/aus“ sowie Türschlösser, Fenster und Türen mit Statusanzeige.

## Module

| Modul | Beschreibung |
| --- | --- |
| [QuickSwitch](QuickSwitch/README.md) | Eine Kachel pro Instanz mit beliebig vielen Aktoren (Boolean-Variablen mit Aktion). |
| [QuickLock](QuickLock/README.md) | Eine Kachel pro Instanz mit beliebig vielen Türschlössern (z. B. Nuki über Matter): Statusanzeige, Verriegeln/Aufschließen per Antippen, Öffnen über Menü mit Rückfrage. |
| [QuickWindow](QuickWindow/README.md) | Eine Kachel pro Instanz mit beliebig vielen Fenstern und Türen: Zustand aus Reed-Kontakt und Fenstergriffen (z. B. Siegenia über Matter), Verriegeln der Griffe über ein Menü. |

## Voraussetzungen

- IP-Symcon ab Version 9.1

Die Module sind auf Deutsch und Englisch verfügbar (Sprache der Konsole bzw. Visualisierung).

## Installation

Die Library wird über das Module Control von IP-Symcon installiert:

1. In der Konsole unter **Kern Instanzen** die Instanz **Modules** öffnen.
2. Auf **+** klicken und die Repository-URL eintragen:

   ```
   https://github.com/Loerdy/symcon-quickswitch
   ```

3. Eine Instanz **QuickSwitch** anlegen („Instanz hinzufügen“, Suche nach „QuickSwitch“ oder „Schnellschalter“) und die Aktoren eintragen bzw. eine Instanz **QuickLock** (Suche nach „QuickLock“ oder „Schnellschloss“) und die Schlösser eintragen bzw. eine Instanz **QuickWindow** (Suche nach „QuickWindow“ oder „Schnellfenster“) und die Fenster und Türen eintragen.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
