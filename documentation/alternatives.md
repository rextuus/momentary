# Alternativen zu Jellyfin

Obwohl Jellyfin die empfohlene Lösung für Momentary ist, gibt es andere Optionen für das Video-Streaming und Management.

## 1. Plex

Plex ist einer der bekanntesten Medienserver.

**Vorteile:**
- Extrem ausgereifte Benutzeroberfläche.
- Apps für fast jedes Gerät verfügbar.

**Nachteile:**
- **API-Komplexität**: Der API-Zugriff ist umständlich (X-Plex-Token).
- **Cloud-Abhängigkeit**: Erfordert oft eine Authentifizierung über die Plex-Server.
- **Proprietär**: Nicht Open Source.

## 2. Emby

Emby ist stabil und performant.

**Vorteile:**
- Gute Benutzeroberfläche.
- API vorhanden.

**Nachteile:**
- Proprietär.
- Viele Features hinter einer Bezahlschranke (Emby Premiere).

## 3. Kodi

Kodi ist eher ein Mediaplayer als ein Medienserver.

**Vorteile:**
- Extrem anpassbar.
- Läuft auf fast jeder Hardware.

**Nachteile:**
- Nicht primär für das Streaming an externe Clients über das Netzwerk gedacht.
- Die Verwaltung einer zentralen Datenbank für mehrere Clients ist komplexer als bei Jellyfin.

## Vergleichstabelle

| Feature | Jellyfin | Plex | Emby |
| :--- | :--- | :--- | :--- |
| Lizenz | Open Source | Proprietär | Proprietär |
| API-Komplexität | Niedrig (API Key) | Hoch (Token) | Mittel |
| Cloud-Zwang | Nein | Ja | Teilweise |
| Docker-Support | Exzellent | Exzellent | Exzellent |

## Empfehlung für Momentary

**Jellyfin** bleibt die beste Wahl für Momentary aufgrund der offenen API und der einfachen Integration ohne Cloud-Zwang.
