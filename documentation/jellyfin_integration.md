# Jellyfin Integration

Momentary kann verarbeitete Videos automatisch in eine Jellyfin-Mediathek exportieren.

## Funktionsweise

Die Jellyfin-Integration ermöglicht es, ein Video nach Abschluss der Analyse zu "exportieren". Dabei kopiert Momentary die Videodatei in ein Verzeichnis, das von deinem Jellyfin-Server überwacht wird, und triggert anschließend einen Library-Scan.

### Komponenten

- **`JellyfinUploadService`**: Übernimmt die Dateioperationen und stößt den Scan über die Jellyfin-API an.
- **`Jellyfin-Export`**: In der Video-Detailansicht erscheint ein Button, sobald das Video den Status `COMPLETED` erreicht hat.

Standardmäßig ist Jellyfin im Browser unter folgender Adresse erreichbar (vorausgesetzt das Standard-Port-Mapping von 8096 wird verwendet):
[http://localhost:8096](http://localhost:8096)

## Konfiguration

Für die Integration müssen folgende Umgebungsvariablen in der `.env.local` konfiguriert werden:

```env
# Verwende host.docker.internal, wenn die App in Docker läuft und Jellyfin auf dem Host
JELLYFIN_HOST=http://host.docker.internal:8096
JELLYFIN_API_KEY=dein_jellyfin_api_key
```

### API-Key erstellen
Einen API-Key kannst du in Jellyfin unter **Dashboard > Erweitert > API-Schlüssel** erstellen.

### Jellyfin Upload Verzeichnis
Standardmäßig erwartet die Anwendung, dass Jellyfin Zugriff auf ein bestimmtes Verzeichnis hat. Im Docker-Setup ist dies gemappt auf:
`%kernel.project_dir%/docker/jellyfin/uploads`

Stelle sicher, dass in Jellyfin eine Bibliothek (z. B. "Home Videos") auf dieses Verzeichnis verweist.

## Nutzung

## Funktionsweise

Die Integration ist über den Symfony Messenger entkoppelt.

1.  **Workflow**:
    -   Klick auf "Zu Jellyfin exportieren" im Dashboard oder der Video-Detailansicht.
    -   Eine `ExportVideoToJellyfinMessage` wird in die `async` Queue gestellt.
    -   Der `ExportVideoToJellyfinMessageHandler` kopiert die Datei und triggert den Jellyfin-Library-Scan.

## Fehlerbehebung

### "python3: not found" beim Export
Falls der Messenger-Worker lokal auf dem Host läuft, muss sichergestellt sein, dass Python 3 und FFmpeg installiert sind. Die Anwendung nutzt die Umgebungsvariable `PYTHON_BINARY` aus der `.env` Datei, um den Pfad zum Python-Interpreter zu finden. Standardmäßig ist dies `/usr/bin/python3`.

## Berechtigungen bei lokalem Betrieb

Falls der Symfony Messenger-Worker lokal (außerhalb von Docker) ausgeführt wird, muss sichergestellt werden, dass er Schreibrechte auf das Upload-Verzeichnis hat. Da Docker-Container Verzeichnisse oft als `root` anlegen, kann es zu `Permission Denied` Fehlern kommen.

Lösung:
```bash
# Auf dem Host ausführen
sudo chmod -R 777 docker/jellyfin/uploads/
```

## Funktionsweise des Exports

Der Export erfolgt asynchron über mehrere Schritte:
1. **Optimierung**: Falls das Video kein MP4 ist, wird es via Python-Skript (`convert_to_mp4.py`) und FFmpeg konvertiert.
2. **Transfer**: Die Datei wird in den Jellyfin-Ordner (`docker/jellyfin/uploads`) kopiert.
3. **Scan**: Die Jellyfin-API wird angewiesen, die Bibliothek zu aktualisieren.
4. **ID-Mapping**: Die App sucht nach der internen Jellyfin Item-ID, um stabiles Streaming zu ermöglichen.

## Video-Streaming auf der Detailseite

Auf der Video-Detailseite ist ein HTML5-Player integriert, der das Video direkt von Jellyfin streamt.

**Hinweis zu Videoformaten:**
Einige Formate (wie `.mpg` oder `.avi`) werden von modernen Browsern nicht nativ unterstützt. In diesen Fällen versucht der Player, den Stream über Jellyfin zu beziehen. Falls das Video dennoch nicht lädt:
- Nutze den Button **"In Jellyfin öffnen"**, um das Video direkt in der Jellyfin-Weboberfläche abzuspielen. Jellyfin übernimmt dort automatisch das Transcoding in ein kompatibles Format.
- Stelle sicher, dass die `JELLYFIN_HOST` URL in deiner `.env.local` für deinen Browser erreichbar ist (standardmäßig `http://localhost:8096`).

## Testen der Integration

Um den Export und die API-Anbindung manuell zu testen, steht ein Konsolenbefehl zur Verfügung:

```bash
# Lokal
php bin/console app:jellyfin:test-export <videoId>

# Im Docker Container
docker exec momentary-app-1 php bin/console app:jellyfin:test-export <videoId>
```

Dieser Befehl nimmt die Video-ID eines bereits in der Datenbank existierenden Videos, kopiert die Datei und triggert den Scan.
