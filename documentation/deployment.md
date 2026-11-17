# Momentary auf dem ThinkCentre betreiben

Diese Anleitung bringt Momentary auf den Rechner zu Hause (ThinkCentre „abyss“, Ubuntu 24.04), auf dem auch Wedora,
der Momentary-Hub und das Praxis-CMS laufen. Erreichbar ist das Backend unter `https://admin.momentary.wh-company.com`.

**Aufbau live:** Momentary läuft als Docker-Stack und bietet die Weboberfläche, die API und die Bilder über **einen
HTTP-Port** an (Standard `8088`). HTTPS und Zertifikat übernimmt der **Caddy auf dem VPS** mit fester IP, der die
Anfragen über **WireGuard** an diesen Port weiterleitet – wie bei den anderen Projekten auf dem Rechner.

```
Browser ──HTTPS──▶ VPS (Caddy, Zertifikat) ──WireGuard──▶ ThinkCentre :8088 (app: Apache + Symfony) ──▶ imgproxy (/imgproxy/)
Hub (:8089) ──────── Docker-Netz momentary_momentary-network ──────────▶ app:80, jellyfin:8096
Fernseher/Hub ─HTTPS─▶ VPS ─WireGuard─▶ ThinkCentre :8096 (Jellyfin)
```

| Domain | Ziel auf dem ThinkCentre | Dienst |
|---|---|---|
| `admin.momentary.wh-company.com` | `10.0.0.2:8088` | Momentary-Backend (dieser Stack) |
| `momentary.wh-company.com` | `10.0.0.2:8089` | Momentary-Hub (eigenes Repo `momentary-hub`) |
| `media.momentary.wh-company.com` | `10.0.0.2:8096` | Jellyfin (dieser Stack) |

Getestet am 21.10.2026 als Generalprobe auf dem Entwicklungsrechner (gleiche Befehle, andere Ports, leere Datenbank,
ohne echte Domain, siehe [Generalprobe](#generalprobe-auf-dem-eigenen-rechner)): Bauen, `make prod-up` mit Migration,
alle Dienste gesund, `make prod-status`, `/healthz` (auch 503 bei fehlender Datenbank), Anmeldung, Seiten, Foto über den
Import-Ordner bis zur Auslieferung über `/imgproxy/`, Weiterleitungen hinter einem Proxy (`X-Forwarded-Proto: https`),
`make prod-backup` und Wiederherstellen der Datenbank.

---

## Was auf dem Server läuft

Alles in Docker, gestartet über `compose.prod.yaml` (Projektname `momentary`):

| Dienst | Aufgabe | Von außen erreichbar? |
|---|---|---|
| `app` | Apache + PHP (Symfony): Weboberfläche, API, Video-Streams; reicht `/imgproxy/` an imgproxy weiter | ja, **ein Port** (`HTTP_PORT`, 8088) – für den VPS |
| `messenger-worker` | Video-Pipeline, Fotos, Import-Ordner, Zeitplan | nein |
| `transcription-worker` | Ton-Transkription (Whisper, CPU), eigene Warteschlange, niedrige Priorität | nein |
| `database` | MySQL 8.4 (LTS) | nein |
| `rabbitmq` | Warteschlangen | nur `127.0.0.1:15672` (Verwaltung) |
| `meilisearch` | Suche | nein |
| `imgproxy` | verkleinert Bilder | nein (nur über `app`) |
| `jellyfin` | Video-Streaming für Hub und Fernseher | ja, `JELLYFIN_PORT` (8096) |
| `sftp` | Upload in den Import-Ordner | ja, `SFTP_PORT` (2222) – fürs Heimnetz |

`app` und die beiden Worker nutzen **dasselbe Image** (`momentary-app:prod`, Code ist eingebaut, nichts wird zur
Laufzeit eingebunden außer den Daten).

**Wo die Daten liegen** (ein Neustart oder Update löscht nichts):

| Was | Wo |
|---|---|
| Datenbank | Docker-Volume `momentary_database_data` |
| Videos, Fotos, Gesichter, Import-Ordner | `/opt/momentary/storage/` |
| Suchindex | `/opt/momentary/docker/meilisearch/data/` (lässt sich jederzeit neu aufbauen) |
| Jellyfin-Einstellungen und -Bibliothek | `/opt/momentary/docker/jellyfin/config/` |
| Whisper-Modelle | Docker-Volume `momentary_whisper_models` (lädt sich beim ersten Transkribieren, ~1,6 GB) |
| Secrets | `/opt/momentary/.env.prod.local`, Schlüssel in `config/oauth2/` und `config/jwt/` |

Alles außer den Volumes liegt in Ordnern, die Git ignoriert – `git pull` fasst sie nicht an.

---

## 1. Voraussetzungen

**Server** (läuft bereits für die anderen Projekte)
- Docker Engine mit Compose-Plugin, `git`, `make`, `openssl`
- Platz: Medien + ca. 5 GB für Images und Whisper-Modelle. Prüfen: `df -h /opt`
- Der ThinkCentre (i5-6500T, 16 GB RAM) reicht; Transkription läuft dort etwa in Echtzeit
  (siehe `transcription.md`).

**VPS und Domains**
- DNS-Einträge `admin.momentary.wh-company.com` und `media.momentary.wh-company.com` zeigen auf die VPS-IP
  (`dig +short admin.momentary.wh-company.com`).
- WireGuard steht: ThinkCentre `10.0.0.2`, VPS `10.0.0.1` (`sudo wg show` zeigt einen aktuellen Handshake).

**Ports auf dem ThinkCentre**
- Momentary braucht `8088` (Backend), `8096` (Jellyfin), `2222` (SFTP) und lokal `15672`.
  Prüfen: `ss -ltn | grep -E ':(8088|8096|2222|15672) '` darf nichts ausgeben.
- Belegt durch andere Projekte: u. a. 3307, 7999, 8080 (CMS), 8089 (Hub), 8190 (Wedora).

---

## 2. Code auf den Server bringen

Deployt wird über Git (`https://github.com/rextuus/momentary.git`, **öffentlich** – also nie Secrets committen). Das
Projekt liegt unter **`/opt/momentary`**.

Auf den Server kommt nur, was **committet und gepusht** ist. Vor jedem Deployment lokal prüfen:
`git status -sb` zeigt weder `ahead` noch unversionierte Dateien unter `src/`, `migrations/`, `config/`, `templates/`
oder `video-analyzer/`.

Einmalig auf dem ThinkCentre:

```bash
sudo mkdir -p /opt/momentary && sudo chown "$USER": /opt/momentary
git clone https://github.com/rextuus/momentary.git /opt/momentary
```

Nicht in Git und deshalb auf dem Server dauerhaft erhalten: `.env.prod.local`, `config/oauth2/`, `config/jwt/`,
`storage/`, `docker/jellyfin/`, `docker/meilisearch/`, `backups/`.

---

## 3. Secrets anlegen

```bash
cd /opt/momentary
make prod-secrets
nano .env.prod.local
```

`make prod-secrets` erzeugt `.env.prod.local` aus `.env.prod.dist` mit zufälligen Passwörtern (Rechte 600) und legt
die Schlüsselpaare für die API in `config/oauth2/` und `config/jwt/` an, falls sie fehlen. Eintragen bzw. prüfen:

```dotenv
APP_PUBLIC_URL=https://admin.momentary.wh-company.com
HTTP_PORT=8088
HTTP_BIND=10.0.0.2             # WireGuard-IP: Backend nur über den VPS erreichbar
REVERSE_PROXY_IPS=10.0.0.1     # WireGuard-IP des VPS: nur dessen X-Forwarded-* (https, Besucher-IP) gelten
JELLYFIN_BIND=0.0.0.0          # Jellyfin auch im Heimnetz (Fernseher); nur über VPS: 10.0.0.2
SFTP_BIND=0.0.0.0              # SFTP aus dem Heimnetz; der VPS leitet 2222 nicht weiter
AWS_ACCESS_KEY=…               # Gesichtserkennung
AWS_SECRET_KEY=…
GEMINI_API_KEY=…               # Tags, Titel, Kapitel
JELLYFIN_API_KEY=…             # erst nach dem ersten Start in Jellyfin anlegen (Schritt 6), dann make prod-up
```

- `APP_PUBLIC_URL` steckt in signierten Video-Links (Fernseher, Share-Links) – öffentliche Adresse mit `https://`.
- `IMGPROXY_PUBLIC_HOST` leer lassen: Bilder laufen dann über `<APP_PUBLIC_URL>/imgproxy`.
- `HTTP_BIND`: Docker veröffentlicht Ports an der Firewall (ufw) vorbei. Mit der WireGuard-IP kommt niemand aus dem
  Heimnetz oder Internet am VPS vorbei. Wer das Backend im WLAN direkt (`http://192.168.…:8088`) nutzen will, nimmt
  `0.0.0.0`.
- AWS und Gemini **kosten Geld**. Ohne Schlüssel laufen Import und Oberfläche, die Analyse-Schritte schlagen fehl.
- `.env.prod.local`, `config/oauth2/` und `config/jwt/` **nie** ins Repository; sicher aufbewahren (Passwortmanager).
  Ohne sie lassen sich Sicherungen nicht wieder einspielen und die API-Clients (Hub, App) müssen neu eingerichtet
  werden.

---

## 4. Weiterleitung auf dem VPS

Auf dem **VPS** in `/etc/caddy/Caddyfile` ergänzen:

```caddyfile
# Momentary Backend (Weboberfläche, API, Bilder unter /imgproxy/)
admin.momentary.wh-company.com {
	reverse_proxy 10.0.0.2:8088
}

# Jellyfin
media.momentary.wh-company.com {
	reverse_proxy 10.0.0.2:8096 {
		# Streaming und WebSockets
		flush_interval -1
	}
}
```

Caddy setzt `X-Forwarded-For`, `-Proto` und `-Host` selbst; `header_up`-Zeilen braucht es nicht. imgproxy hat keinen
eigenen Port, Bilder laufen über `admin.momentary…/imgproxy/`. Danach `sudo systemctl reload caddy`.

Vom VPS aus prüfen (nach Schritt 5): `curl -s http://10.0.0.2:8088/healthz` → `{"status":"ok","database":"ok"}`.

---

## 5. Starten

```bash
cd /opt/momentary
make prod-up
```

Baut das Image (beim ersten Mal ca. 10 Minuten, vor allem Python-Pakete), startet alle Dienste, wartet bis sie gesund
sind und migriert die Datenbank. Die Medienordner werden beim Start auf den Besitzer `www-data` gebracht (nur
abweichende Dateien, das geht schnell).

Prüfen:

```bash
make prod-status                                             # alle Dienste laufen, Gesundheit {"status":"ok",…}
curl -I https://admin.momentary.wh-company.com/login          # vom eigenen Rechner: HTTP/2 200 (über den VPS)
```

Im Browser anmelden, ein Video und ein Foto öffnen: Vorschaubilder kommen über `…/imgproxy/…`, das Video spielt.

---

## 6. Einrichten nach dem ersten Start

**Verwaltungskonto:**

```bash
make prod-console CMD="app:user:create du@example.de --role=admin"
```

Das Passwort wird verdeckt abgefragt. Weitere Konten (Familie, Pools): siehe `console_commands.md`.

**Suchindex** (einmalig, und wenn die Suche leer bleibt): `make prod-reindex`.

**Jellyfin:** `https://media.momentary.wh-company.com` öffnen, Assistent durchlaufen,
Bibliothek „Filme/Videos“ mit dem Ordner `/uploads` anlegen. Unter Dashboard → API-Schlüssel einen Schlüssel anlegen,
als `JELLYFIN_API_KEY` in `.env.prod.local` eintragen, `make prod-up`.

**AWS Rekognition:** Gesichts-Collection anlegen –
`make prod-console CMD="app:rekognition:setup"`.

**API-Clients** (Hub, Android-App): in der Weboberfläche unter Verwalten → „API-Zugänge“; Zugangsdaten in die
`.env` des Hubs. Den Hub erst danach deployen – er hängt am Docker-Netz `momentary_momentary-network`, das dieser
Stack anlegt.

---

## 7. Updates einspielen

Lokal committen und `git push`, dann auf dem Server:

```bash
cd /opt/momentary
make prod-backup   # empfohlen
git pull
make prod-up
```

Baut neu (nur geänderte Schichten), ersetzt die Container von `app` und Workern, migriert die Datenbank. Laufende
Video-Analysen werden dabei unterbrochen und danach neu zugestellt; eine laufende Transkription beginnt von vorn. Wer
das vermeiden will, wartet, bis `make prod-status` leere Warteschlangen zeigt.

Ändert sich nur der Hub: im Hub-Repo deployen, hier nichts zu tun.

---

## 8. Sicherung und Wiederherstellung

```bash
make prod-backup
```

Legt in `backups/<datum>/` (Rechte 700) ab: Datenbank-Dump, Jellyfin-Einstellungen und die Secrets
(`.env.prod.local`, API-Schlüssel). **Die Medien** (`storage/`, viele GB) sichert das nicht – dafür regelmäßig auf eine
andere Platte oder ein NAS spiegeln, z. B.:

```bash
rsync -a /opt/momentary/storage/ /mnt/sicherung/momentary-storage/
rsync -a /opt/momentary/backups/ /mnt/sicherung/momentary-backups/
```

(Ohne `--delete`: so überlebt auch ein versehentlich gelöschtes Video. Ein Suchindex muss nicht gesichert werden,
`make prod-reindex` baut ihn neu.)

Alte Sicherungen aufräumen (älter als 14 Tage): `find backups -mindepth 1 -maxdepth 1 -mtime +14 -exec rm -rf {} +`.

**Wiederherstellen** (frischer Server, Repo geklont, Medien zurück nach `storage/` kopiert):

```bash
tar -xzf backups/<datum>/schluessel.tar.gz              # .env.prod.local, config/oauth2, config/jwt
sudo mkdir -p docker/jellyfin && sudo tar -xzf backups/<datum>/jellyfin-config.tar.gz -C docker/jellyfin
make prod-up
gunzip -c backups/<datum>/datenbank.sql.gz \
  | docker compose -p momentary -f compose.prod.yaml --env-file .env.prod.local exec -T database sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'
make prod-up
make prod-reindex
```

---

## 9. Betrieb

| Was | Befehl |
|---|---|
| **Zustand auf einen Blick** (Container, Gesundheit, Speicherplatz, Warteschlangen) | `make prod-status` |
| Logs ansehen (alle / ein Dienst) | `make prod-logs` / `make prod-logs S=messenger-worker` |
| Konto anlegen / Passwort neu setzen | `make prod-console CMD="app:user:create du@example.de --role=admin"` / `… --reset-password` |
| Fehlgeschlagene Hintergrundjobs | `make prod-console CMD="messenger:failed:show"` |
| Fehlgeschlagene Jobs erneut versuchen | `make prod-console CMD="messenger:failed:retry"` |
| Video transkribieren | `make prod-console CMD="app:video:transcribe <Video-ID>"` |
| Suchindex neu aufbauen | `make prod-reindex` |
| RabbitMQ-Verwaltung | `ssh -L 15672:127.0.0.1:15672 master@abyss`, dann `http://localhost:15672` |
| Alles stoppen (Daten bleiben) | `make prod-down` |

**Läuft von selbst:**
- **Zeitplan im `messenger-worker`** (Symfony Scheduler): u. a. Import-Ordner prüfen, wenn `IMPORT_FOLDER_WATCH=1`.
  Kein Cron nötig.
- **Worker-Neustart:** Die Worker beenden sich nach 1 h (Transkription 2 h) bzw. 512 MB und werden von Docker neu
  gestartet – so wächst kein Speicher.
- **Log-Begrenzung:** Docker behält je Dienst höchstens 5 × 10 MB Logs.

**Überwachung (empfohlen):** Externer Uptime-Check auf `https://admin.momentary.wh-company.com/healthz` (z. B. Uptime
Kuma, UptimeRobot). Antwortet mit `{"status":"ok","database":"ok"}`, sonst mit HTTP 503.

---

## 10. Wenn etwas nicht klappt

- **502 Bad Gateway vom VPS:** Der VPS erreicht das Backend nicht. Auf dem VPS `curl http://10.0.0.2:8088/healthz`;
  klappt das nicht: WireGuard (`sudo wg show`), `HTTP_BIND`/`HTTP_PORT` in `.env.prod.local`, `make prod-status`.
- **„… fehlt in .env.prod.local“ beim Start:** Wert in `.env.prod.local` fehlt (Schritt 3).
- **`make prod-up` bricht bei der Migration mit „Access denied“ ab:** Die Datenbank-Passwörter in `.env.prod.local`
  wurden nach dem ersten Start geändert – MySQL übernimmt sie nur beim allerersten Start. Alte Werte wieder eintragen
  (oder, solange noch keine Daten drin sind: `make prod-down`, `docker volume rm momentary_database_data`, `make prod-up`).
- **Bilder fehlen, Seite lädt:** `imgproxy` läuft? (`make prod-status`). `IMGPROXY_PUBLIC_HOST` leer oder
  `<APP_PUBLIC_URL>/imgproxy`? Im Browser ein Bild direkt öffnen – 403 heißt: `IMGPROXY_KEY`/`SALT` passen nicht.
- **Links mit `http://` statt `https://` oder Anmeldung springt zurück:** `APP_PUBLIC_URL` mit `https://`;
  `REVERSE_PROXY_IPS` muss die WireGuard-IP des VPS enthalten (oder `PRIVATE_SUBNETS`).
- **Suche leer oder Meilisearch startet nicht** (z. B. nach Versionswechsel): Stack stoppen, Ordner
  `docker/meilisearch/data` umbenennen, `make prod-up`, `make prod-reindex`.
- **Video bleibt bei „wird verarbeitet“:** `make prod-logs S=messenger-worker`; fehlgeschlagene Jobs mit
  `messenger:failed:show`. Häufigste Ursache: AWS- oder Gemini-Schlüssel fehlt oder Kontingent erschöpft.
- **Hub zeigt nichts mehr:** Läuft der Hub-Container und hängt er am Netz `momentary_momentary-network`?
  (`docker network inspect momentary_momentary-network`). Nach `make prod-down` den Hub neu starten.
- **SFTP-Programm warnt vor geändertem Host-Schlüssel:** Der SFTP-Container erzeugt seine Schlüssel bei jedem
  Neuanlegen neu; Warnung bestätigen bzw. alten Eintrag aus `known_hosts` löschen.

---

## Generalprobe auf dem eigenen Rechner

Vor dem ersten Deployment lässt sich der Produktions-Stack lokal starten – getrennt von der Entwicklungsumgebung (eigener
Projektname, eigene Ports, eigene Datenordner unter `DATA_ROOT`):

```bash
P=$(mktemp -d)
make prod-secrets PROD_ENV=$P/probe.env
sed -i "s#^APP_PUBLIC_URL=.*#APP_PUBLIC_URL=http://localhost:8290#; s/^HTTP_PORT=.*/HTTP_PORT=8290/; s/^HTTP_BIND=.*/HTTP_BIND=127.0.0.1/; s/^JELLYFIN_PORT=.*/JELLYFIN_PORT=8296/; s/^JELLYFIN_BIND=.*/JELLYFIN_BIND=127.0.0.1/; s/^SFTP_PORT=.*/SFTP_PORT=2322/; s/^SFTP_BIND=.*/SFTP_BIND=127.0.0.1/" $P/probe.env
printf 'DATA_ROOT=%s\nRABBITMQ_MANAGEMENT_PORT=15772\n' $P >> $P/probe.env
make prod-up PROD_ENV=$P/probe.env PROD_PROJECT=momentary-probe
make prod-status PROD_ENV=$P/probe.env PROD_PROJECT=momentary-probe
curl -I http://localhost:8290/login                              # HTTP/1.1 200

# aufräumen
docker compose -p momentary-probe -f compose.prod.yaml --env-file $P/probe.env down -v
sudo rm -rf $P
```
