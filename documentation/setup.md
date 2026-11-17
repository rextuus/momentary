# Entwicklungsumgebung

Alles läuft in Docker (`compose.yaml`); der Code wird in die Container eingebunden, Änderungen wirken sofort
(Worker nach Codeänderungen neu starten: `docker compose restart messenger-worker transcription-worker`).
Produktion: [deployment.md](deployment.md).

## Einrichten

```bash
cp .env.example .env          # Schlüssel für AWS, Gemini, Jellyfin eintragen (kosten teils Geld)
make up                       # baut das Image (einmal für app und beide Worker) und startet alles
docker compose exec -T app composer install
docker compose exec -T app bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T app bin/console app:user:create du@example.de --role=admin
```

Für die API (Hub, Android-App) braucht es Schlüsselpaare in `config/oauth2/` und `config/jwt/`; `bin/prod-secrets`
legt sie an, falls sie fehlen (die Datei `.env.prod.local`, die es dabei ebenfalls anlegt, kann lokal gelöscht werden).

Rechnerspezifisches (z. B. `APP_PUBLIC_URL=http://192.168.178.56:8088` für Handy und Fernseher im WLAN) gehört in
`.env.local`.

## Dienste

| Dienst | Aufgabe | Lokal erreichbar |
|---|---|---|
| `app` | Apache + PHP (Symfony), Python-Skripte, ffmpeg | `APP_PORT` (8088) |
| `messenger-worker` | Video-Pipeline, Fotos, Import, Zeitplan | – |
| `transcription-worker` | Whisper-Transkription (eigene Queue) | – |
| `database` | MySQL 8.4 LTS | – |
| `rabbitmq` | Warteschlangen | Verwaltung auf 127.0.0.1:15672 |
| `meilisearch` | Suche | – |
| `imgproxy` | Bilder verkleinern | `IMGPROXY_PORT` (8081) |
| `jellyfin` | Video-Streaming für den Hub | `JELLYFIN_PORT` (8096) |
| `sftp` | Upload in den Import-Ordner | `SFTP_PORT` (2222) |

## Prüfen

```bash
make test                     # PHPUnit (SQLite, var/storage – nie die Dev-Datenbank)
make qa                       # Mago, PHPStan Level 8, Tests
```

Mehr dazu in [testing.md](testing.md) und [code-quality.md](code-quality.md).

## Python

Die Skripte unter `video-analyzer/python/` laufen im Container mit dem System-Python; die Pakete stehen in
`docker/php/requirements.txt`. Nach Änderungen daran oder am `Dockerfile`: `make up` (baut neu).
