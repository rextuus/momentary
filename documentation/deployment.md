# Momentary auf abyss betreiben (Komodo)

Momentary läuft als Komodo-Stack `momentary` auf abyss (ThinkCentre, Ubuntu 26.04) und ist unter
`https://admin.momentary.wh-company.com` erreichbar. Deployt wird über Komodo aus GitHub (`rextuus/momentary`,
Branch `main`); die Ressourcen stehen in [`deploy/komodo/momentary.toml`](../deploy/komodo/momentary.toml).
Übergreifendes (Komodo, Caddy, Backups, Offsite) steht im Repo `homelab` (`docs/komodo.md`, `docs/backups.md`,
`docs/offsite.md`).

```
Browser ──HTTPS──▶ VPS (Caddy, TLS) ──WireGuard──▶ abyss 10.0.0.2:8194 (app: Apache + Symfony) ──▶ imgproxy (/imgproxy/)
Hub ──────────── Docker-Netz momentary_momentary-network ─────────────▶ app:80
app/Worker ───── intern ───────────────────────────────────────────────▶ jellyfin:8096 (Export-Ziel, kein Port)
```

---

## Was läuft

`compose.prod.yaml`, Projektname `momentary`. **Nur `app` hat einen Host-Port** (`10.0.0.2:8194`).

| Dienst | Aufgabe | Art |
|---|---|---|
| `migrate` | Datenbank-Migrationen, einmal je Deploy, beendet sich | One-Shot (Komodo ignoriert ihn) |
| `app` | Apache + PHP: Weboberfläche, API, Video-Streams, `/imgproxy/` → imgproxy | dauerhaft, Healthcheck `/healthz` (bis zur DB) |
| `messenger-worker` | Video-Pipeline, Fotos, Import-Ordner, Zeitplan (Symfony Scheduler) | dauerhaft |
| `transcription-worker` | Whisper-Transkription, niedrige Priorität | dauerhaft |
| `database` | MySQL 8.4 | dauerhaft |
| `rabbitmq` | Warteschlangen (Verwaltung nur per `exec`) | dauerhaft |
| `meilisearch` | Suche | dauerhaft |
| `imgproxy` | Bildvarianten, nur über `app` erreichbar | dauerhaft |
| `jellyfin` | Ziel des Video-Exports (Pipeline-Schritt 22), intern | dauerhaft |
| `backup` | Datenbank-Dump nach `/srv/momentary/backups` (`deploy/komodo/db-backup.sh`) | nur auf Abruf (Profil) |

Reihenfolge beim Start: `database` → `migrate` → `app` (gesund) → Worker und Jellyfin. Komodo deployt mit
`up -d --wait`: Der Deploy gilt erst als erfolgreich, wenn alle Dienste gesund sind.

### Wo die Daten liegen

Nichts liegt im Stack-Ordner `/etc/komodo/stacks/momentary` – Komodo darf ihn jederzeit neu klonen.

| Was | Wo | Gesichert |
|---|---|---|
| Datenbank | Volume `momentary_database_data` | täglich 02:00 + vor jedem Deploy als Dump; Dumps auch offsite |
| Medien (Videos, Fotos, Gesichter, Import-Ordner, Jellyfin-Uploads) | `/srv/momentary/storage` (Bind-Mount) | **nicht** (bewusst, zu groß für B2 – siehe [Sicherung](#sicherung-und-wiederherstellung)) |
| API-Schlüssel (OAuth2, JWT) | `/srv/momentary/keys/{oauth2,jwt}` (nur lesend eingebunden) | offsite (`backup.offsite_paths`) |
| Dumps | `/srv/momentary/backups` | offsite (`backup.target`) |
| Suchindex | Volume `momentary_meilisearch_data` | nein, `app:meilisearch:index-all` baut neu |
| Warteschlangen | Volume `momentary_rabbitmq_data` | nein |
| Jellyfin-Einstellungen, Cache | Volumes `momentary_jellyfin_config`, `momentary_jellyfin_cache` | nein (schnell neu eingerichtet) |
| Whisper-Modelle | Volume `momentary_whisper_models` | nein, lädt sich neu (~1,6 GB) |
| Secrets | Komodo-Variablen `MOMENTARY_*` | Komodo-Datenbank-Backup (01:00) |

Die Volume-Namen sind in `compose.prod.yaml` per `name:` festgelegt und hängen nicht am Projektnamen. **Nie
umbenennen.** `VOLUME_PREFIX` nur für Probeläufe setzen (siehe [Probelauf](#probelauf-auf-dem-entwicklungsrechner)).

### Variablen

Die vollständige Liste mit Erklärung steht in [`.env.prod.dist`](../.env.prod.dist). Komodo schreibt bei jedem
Deploy `.env.prod.local` aus dem Environment in `momentary.toml`. Nicht geheime Werte stehen dort direkt
(`APP_PUBLIC_URL`, `HTTP_BIND=10.0.0.2`, `HTTP_PORT=8194`, `REVERSE_PROXY_IPS=10.0.0.1`, Pfade unter `/srv/momentary`),
Secrets als Komodo-Variablen:

| Komodo-Variable (Secret) | Wert |
|---|---|
| `MOMENTARY_APP_SECRET` | `openssl rand -hex 32` |
| `MOMENTARY_MYSQL_PASSWORD` | `openssl rand -hex 16` – gilt nur beim allerersten Start, danach nie ändern |
| `MOMENTARY_MYSQL_ROOT_PASSWORD` | `openssl rand -hex 16` – wie oben |
| `MOMENTARY_RABBITMQ_PASS` | `openssl rand -hex 16` – wie oben (RabbitMQ-Volume) |
| `MOMENTARY_MEILI_MASTER_KEY` | `openssl rand -hex 32` |
| `MOMENTARY_IMGPROXY_KEY` | `openssl rand -hex 32` |
| `MOMENTARY_IMGPROXY_SALT` | `openssl rand -hex 32` |
| `MOMENTARY_OAUTH_ENCRYPTION_KEY` | `openssl rand -base64 32` – Änderung macht ausgegebene API-Tokens ungültig |
| `MOMENTARY_MAILER_DSN` | `null://null` (Momentary verschickt keine Mails) |
| `MOMENTARY_AWS_ACCESS_KEY`, `MOMENTARY_AWS_SECRET_KEY` | AWS-Konsole, IAM-Benutzer mit Rekognition-Rechten (kostet Geld) |
| `MOMENTARY_GEMINI_API_KEY` | Google AI Studio (kostet Geld) |
| `MOMENTARY_JELLYFIN_API_KEY` | später: Jellyfin → Dashboard → API-Schlüssel (siehe [Einrichten](#5-einrichten-nach-dem-ersten-start)) |

Ohne AWS- oder Gemini-Schlüssel laufen Import und Oberfläche, nur die Analyse-Schritte schlagen fehl. Soll ein
Schlüssel fehlen, die Zeile im Environment von `momentary.toml` löschen – eine nicht angelegte Variable lässt den
Pre-Deploy abbrechen (Komodo lässt `[[…]]` sonst wörtlich stehen).

---

## Inbetriebnahme (erster Deploy)

Momentary lief bisher nirgends; es startet **leer**, es gibt keine Daten zu übernehmen und keine Ausfallzeit. Die
Reihenfolge ist so gewählt, dass jeder Schritt gefahrlos ist und sich prüfen lässt, bevor Caddy die Domain freigibt.

### 1. Code

`git diff` prüfen (Volume-Namen, Ports, keine Secrets), committen, pushen. Auf den Server kommt nur, was auf `main`
in GitHub liegt.

### 2. GitHub

Das Repo `rextuus/momentary` zum fein granularen Token von Komodo (`komodo-abyss`, Settings → Developer settings →
Fine-grained tokens → Repository access) hinzufügen.

### 3. abyss vorbereiten

```bash
ssh abyss
ss -ltn | grep ':8194 '                                  # darf nichts ausgeben
sudo install -d -m 700 /srv/momentary /srv/momentary/backups /srv/momentary/keys
sudo install -d -m 755 /srv/momentary/storage
# API-Schlüsselpaare (OAuth2 für Hub/App, JWT) – nur einmal; neue Schlüssel machen ausgegebene Tokens ungültig
sudo sh -c 'umask 077; cd /srv/momentary/keys && mkdir -p oauth2 jwt &&
  openssl genrsa -out oauth2/private.key 2048 && openssl rsa -in oauth2/private.key -pubout -out oauth2/public.key &&
  openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out jwt/private.pem &&
  openssl pkey -in jwt/private.pem -pubout -out jwt/public.pem'
sudo ls -lR /srv/momentary/keys
df -h /srv                                               # Platz für die Medien
```

`/srv/momentary/storage` darf root gehören: `app` bringt die Ordner beim Start auf `www-data`. Die Schlüssel bleiben
root/600; der Container kopiert sie beim Start mit den Rechten, die die Bibliotheken verlangen.

### 4. Komodo

1. **Settings → Variables:** alle Variablen aus der [Tabelle](#variablen) anlegen, jeweils mit Häkchen „Secret“
   (außer `MOMENTARY_JELLYFIN_API_KEY`, die kommt später). Werte zusätzlich im Passwortmanager ablegen.
2. **Syncs → New Resource Sync:** Mode „Git Repo“, Repo `rextuus/momentary`, Branch `main`, Account `rextuus`,
   Resource Path `deploy/komodo/momentary.toml`, „Delete Unmatched“ **aus**. Refresh → Änderungen prüfen (ein neuer
   Stack `momentary`, eine neue Procedure `momentary-backup`) → Execute Sync.
3. **Stack `momentary` → Deploy.** Ablauf: `git pull` → Pre-Deploy (meldet „Noch keine Datenbank vorhanden –
   Sicherung übersprungen“) → Bauen (erstes Mal ca. 10 Minuten, Python-Pakete) → `up -d --wait` → Post-Deploy (Zustand,
   `/healthz`, Warteschlangen).
4. **Procedure `momentary-backup` → Run.** Danach `sudo ls -l /srv/momentary/backups` → eine Datei
   `momentary-db-…-daily.sql.gz` mit Rechten 600.

### 5. Smoke-Test (vor Caddy)

Auf abyss:

```bash
curl -s http://10.0.0.2:8194/healthz                    # {"status":"ok","database":"ok"}
curl -sI http://10.0.0.2:8194/login | head -1            # HTTP/1.1 200 OK
sudo docker volume ls | grep momentary_                  # momentary_database_data usw.
```

Im Komodo-UI: alle Dienste „running/healthy“, `migrate` „exited (0)“.

### 6. Einrichten nach dem ersten Start

Befehle im Stack-Ordner; Kurzform für die folgenden Abschnitte:

```bash
cd /etc/komodo/stacks/momentary
alias dc='sudo docker compose -p momentary -f compose.prod.yaml --env-file .env.prod.local'
```

- **Verwaltungskonto:** `dc exec -u www-data app php bin/console app:user:create du@example.de --role=admin`
  (Passwort wird verdeckt abgefragt; weitere Konten: `console_commands.md`).
- **Suchindex:** `dc exec -T -u www-data app php bin/console app:meilisearch:index-all`
- **AWS Rekognition:** `dc exec -T -u www-data app php bin/console app:rekognition:setup` (legt die Collection an,
  kostet nichts).
- **Jellyfin** hat keinen Port nach außen. Einrichten über einen SSH-Tunnel zur Container-IP:
  ```bash
  ssh abyss "sudo docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' momentary-jellyfin-1"
  ssh -L 8096:<IP>:8096 abyss          # dann http://localhost:8096
  ```
  Assistent durchlaufen, Bibliothek „Filme/Videos“ mit dem Ordner `/uploads`. Unter Dashboard → API-Schlüssel einen
  Schlüssel anlegen → Komodo-Variable `MOMENTARY_JELLYFIN_API_KEY` (Secret), in `momentary.toml` im Environment die
  Zeile `JELLYFIN_API_KEY = [[MOMENTARY_JELLYFIN_API_KEY]]` ergänzen (beim Kommentar zu Jellyfin), committen, pushen,
  Sync ausführen, Deploy.
- **API-Clients** (Hub, Android-App): in der Weboberfläche unter Verwalten → „API-Zugänge“.

### 7. Domain freischalten (Repo homelab, dort ändern – nicht hier)

1. DNS: `admin.momentary.wh-company.com` → VPS (`dig +short admin.momentary.wh-company.com` = 217.154.91.97).
2. `inventory.yaml`: den Eintrag `momentary` durch den [Block unten](#eintrag-im-homelab-inventar) ersetzen.
3. `bin/inventory` → `bin/gen-docs` → `bin/gen-caddy` → `bin/gen-offsite` → committen →
   `bin/caddy-deploy` (Trockenlauf; Komodo muss Port 8194 bestätigen) → `bin/caddy-deploy --apply`.
4. Prüfen: `curl -sI https://admin.momentary.wh-company.com/login` → `HTTP/2 200`; im Browser anmelden, ein Foto
   hochladen, Vorschaubild kommt über `…/imgproxy/…`.
5. `bin/monitors` → `bin/monitors --apply`, dann `bin/check` – grün?

**Rückfallebene:** Es gibt keinen alten Betrieb. Geht etwas schief: Stack in Komodo stoppen (Daten bleiben) oder im
Inventar `maintenance: true` setzen und Caddy ausrollen (Wartungsseite). Ganz von vorn (nur solange keine echten Daten
drin sind): Stack „Destroy“, `sudo docker volume rm momentary_database_data momentary_rabbitmq_data`, Deploy.

---

## Deploy (Updates)

Lokal committen und pushen, dann in Komodo **Stack `momentary` → Deploy**. Automatische Deploys sind aus.

Was passiert: Pre-Deploy sichert die Datenbank (`momentary-db-…-pre-deploy.sql.gz`, Abbruch bei Fehler oder fehlender
Variable) → Images bauen → `migrate` → neue Container → Post-Deploy räumt alte Images **dieses** Projekts auf.

Laufende Video-Analysen werden unterbrochen und danach neu zugestellt, eine laufende Transkription beginnt von vorn.
Wer das vermeiden will, wartet auf leere Warteschlangen (`dc exec -T rabbitmq rabbitmqctl list_queues name messages`).

Ändert sich nur `momentary.toml` (z. B. Environment): Sync ausführen, dann Deploy.

## Rollback

1. In `momentary.toml` `commit = "<hash>"` setzen (letzter guter Stand), pushen, Sync, Deploy. Alternativ im
   Komodo-UI beim Stack den Commit eintragen – der nächste Sync setzt ihn zurück.
2. Hat der fehlerhafte Stand Migrationen ausgeführt, passt das alte Schema nicht mehr: vorher den Dump
   `…-pre-deploy.sql.gz` dieses Deploys einspielen (siehe [Wiederherstellen](#datenbank-wiederherstellen)).
3. Danach `commit = ""` wieder leeren.

Schlägt `migrate` fehl, sind `app` und Worker schon gestoppt und starten nicht – die Seite ist unten, bis der Fehler
behoben oder der Rollback durch ist. Ursache: `dc logs migrate`.

---

## Sicherung und Wiederherstellung

**Lokal:** Der Dienst `backup` schreibt gzip-Dumps nach `/srv/momentary/backups` (700, Dateien 600, atomar über eine
`.partial`-Datei, abgeschlossen geprüft). Anlässe: `daily` (Procedure `momentary-backup`, 02:00), `pre-deploy`,
`manuell` (`dc run --rm --no-TTY backup manuell`). Je Anlass bleiben die neuesten 14 (`BACKUP_KEEP`).

**Außer Haus:** Die nächtliche Offsite-Sicherung von abyss (05:15, restic → Backblaze B2) nimmt `/srv/momentary/backups`
und `/srv/momentary/keys` mit (aus dem Inventar). Wiederherstellen daraus: `homelab/docs/offsite.md`.

> **Medien sind nicht außer Haus gesichert.** `/srv/momentary/storage` geht bewusst nicht nach B2 (Datenmenge). Ein
> Plattendefekt auf abyss bedeutet: Datenbank und Schlüssel sind wiederherstellbar, die Videos und Fotos nicht. Die
> Originale also an anderer Stelle behalten oder die Medien regelmäßig auf eine zweite Platte spiegeln
> (`rsync -a /srv/momentary/storage/ <ziel>/`).

### Datenbank wiederherstellen

```bash
cd /etc/komodo/stacks/momentary
sudo ls -lt /srv/momentary/backups | head
dc stop app messenger-worker transcription-worker
sudo gunzip -c /srv/momentary/backups/momentary-db-<zeit>-<anlass>.sql.gz \
  | dc exec -T database sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'
dc start app messenger-worker transcription-worker
dc exec -T -u www-data app php bin/console app:meilisearch:index-all
```

Der Dump enthält `CREATE DATABASE`/`USE` und ersetzt die Tabellen. Auf einem frischen Server: erst Schritte 3–4 der
[Inbetriebnahme](#inbetriebnahme-erster-deploy) mit den **bisherigen** Variablenwerten (Komodo-Backup) und den
gesicherten Schlüsseln, dann wie oben einspielen.

---

## Betrieb

| Was | Befehl (`dc` wie oben) |
|---|---|
| Zustand | Komodo-UI, oder `dc ps` und `curl -s http://10.0.0.2:8194/healthz` |
| Logs | Komodo-UI, oder `dc logs -f --tail=100 messenger-worker` |
| Konsole | `dc exec -u www-data app php bin/console <befehl>` |
| Konto anlegen / Passwort neu | `… app:user:create du@example.de --role=admin` / `… --reset-password` |
| Fehlgeschlagene Jobs | `… messenger:failed:show`, `… messenger:failed:retry` |
| Video transkribieren | `… app:video:transcribe <Video-ID>` |
| Warteschlangen | `dc exec -T rabbitmq rabbitmqctl list_queues name messages` |
| Speicher | `df -h /srv/momentary/storage` |
| Medien ablegen (Import-Ordner) | `rsync` nach `abyss:/srv/momentary/storage/imports/`, dann Verwalten → Import-Ordner |

Die Worker beenden sich nach 1 h (Transkription 2 h) bzw. 512 MB und werden neu gestartet. Docker-Logs: höchstens
5 × 10 MB je Dienst. Externe Überwachung: `https://admin.momentary.wh-company.com/healthz` (UptimeRobot über
`bin/monitors` im Repo homelab).

Ohne Komodo (Notfall): im Stack-Ordner `sudo make prod-up`, `sudo make prod-backup`, `sudo make prod-status` – sofern
`make` installiert ist; sonst die `dc`-Befehle. Achtung: Komodo überschreibt `.env.prod.local` beim nächsten Deploy.

---

## Fehlersuche

- **Pre-Deploy bricht ab „In Komodo fehlen diese Variablen“:** Variable unter Settings → Variables anlegen
  (Namen genau wie angezeigt) oder Zeile aus dem Environment in `momentary.toml` entfernen.
- **Pre-Deploy bricht ab wegen VOLUME_PREFIX:** In Produktion darf `VOLUME_PREFIX` nicht gesetzt sein.
- **Deploy scheitert mit „bind source path does not exist“:** `/srv/momentary/storage`, `…/keys` oder `…/backups`
  fehlt (Inbetriebnahme Schritt 3).
- **Deploy hängt bis zum Timeout:** `dc ps` – welcher Dienst ist nicht „healthy“? `dc logs <dienst>`. `migrate` mit
  „Access denied“: Datenbank-Passwörter wurden nach dem ersten Start geändert – alte Werte in Komodo zurück.
- **502 vom VPS:** auf dem VPS `curl http://10.0.0.2:8194/healthz`; WireGuard (`sudo wg show`), Stack läuft?
- **`/healthz` 503:** Datenbank nicht erreichbar – `dc logs database`.
- **API-Clients melden „invalid key“ / 500 bei Token:** Schlüssel unter `/srv/momentary/keys` fehlen oder sind
  vertauscht (`dc logs app`); nach Austausch der Schlüssel `dc restart app`.
- **Bilder fehlen:** `imgproxy` gesund? 403 beim direkten Öffnen: `IMGPROXY_KEY`/`SALT` passen nicht.
- **Links mit `http://`, Anmeldung springt zurück:** `APP_PUBLIC_URL` mit `https://`, `REVERSE_PROXY_IPS=10.0.0.1`.
- **Suche leer / Meilisearch startet nach Versionswechsel nicht:** Stack stoppen,
  `sudo docker volume rm momentary_meilisearch_data`, Deploy, Suchindex neu aufbauen.
- **Video bleibt bei „wird verarbeitet“:** `dc logs messenger-worker`, `messenger:failed:show`. Häufig: AWS-/Gemini-
  Schlüssel fehlt oder Kontingent erschöpft; Jellyfin-Export ohne `JELLYFIN_API_KEY`.
- **Backup-Procedure schlägt fehl:** läuft der Stack? (`no_deps`: bei gestopptem Stack scheitert sie absichtlich).
  Sonst Log der Procedure; Ordner `/srv/momentary/backups` vorhanden?
- **Hub sieht das Backend nicht:** Netz `momentary_momentary-network` vorhanden und Hub daran?
  (`sudo docker network inspect momentary_momentary-network`).

---

## Probelauf auf dem Entwicklungsrechner

Getrennt von der Entwicklungsumgebung: eigener Projektname, eigener `VOLUME_PREFIX` (**Pflicht** – die Entwicklung
heißt ebenfalls `momentary` und hat `momentary_database_data`), Wegwerf-Ordner statt `/srv`.

```bash
P=$(mktemp -d); mkdir -p $P/storage $P/backups
bin/prod-secrets $P/probe.env $P/keys
sed -i "s#^APP_PUBLIC_URL=.*#APP_PUBLIC_URL=http://localhost:8294#; s/^HTTP_PORT=.*/HTTP_PORT=8294/; s/^HTTP_BIND=.*/HTTP_BIND=127.0.0.1/; s#^STORAGE_DIR=.*#STORAGE_DIR=$P/storage#; s#^KEYS_DIR=.*#KEYS_DIR=$P/keys#; s#^BACKUP_DIR=.*#BACKUP_DIR=$P/backups#; s/^\#VOLUME_PREFIX=.*/VOLUME_PREFIX=momentaryprobe/" $P/probe.env
export COMPOSE_PROJECT=momentary-probe ENV_FILE=$P/probe.env
DC="docker compose -p $COMPOSE_PROJECT -f compose.prod.yaml --env-file $ENV_FILE"
sh deploy/komodo/pre-deploy.sh && $DC up -d --build --wait --wait-timeout 600 && sh deploy/komodo/post-deploy.sh
$DC run --rm --no-TTY --no-deps backup daily
curl -s http://localhost:8294/healthz

# aufräumen – nur das Probe-Projekt
$DC down -v && sudo rm -rf $P
```

Realistischer: `pre-deploy.sh`/`post-deploy.sh` im Periphery-Image ausführen (`ghcr.io/moghtech/komodo-periphery:2`,
Docker-Socket und Repo unter demselben Pfad einbinden, `-w <repo>`, `--entrypoint sh`).

---

## Eintrag im homelab-Inventar

Ersetzt den bisherigen Eintrag `momentary` (Status `retired`) in `homelab/inventory.yaml`:

```yaml
  momentary:
    description: Verwaltung von Familien Videos und Fotos
    repo: rextuus/momentary
    branch: main
    host: abyss
    komodo_stack: momentary
    compose_project: momentary
    status: live-komodo
    ingress: caddy
    domains:
      - admin.momentary.wh-company.com
    former_domains: # aufgegeben, nie in Betrieb
      - momentary.wh-company.de
      - admin.momentary.wh-company.de
      - media.momentary.wh-company.de
    monitor: { path: /healthz }
    port: 8194
    bind: 10.0.0.2
    # Kein caddy:-Block nötig: Caddy setzt X-Forwarded-* selbst, große Uploads und Range-Requests laufen ohne
    # Sonderwünsche durch.
    data:
      - /srv/momentary/storage (Bind-Mount, Medien – nicht offsite)
      - /srv/momentary/keys (API-Schlüssel OAuth2/JWT)
      - Named Volumes momentary_* (database_data, rabbitmq_data, meilisearch_data, jellyfin_config, jellyfin_cache, whisper_models)
      - /srv/momentary/backups
    backup:
      time: "02:00"
      what: DB
      target: /srv/momentary/backups
      active: true
      offsite_paths:
        - /srv/momentary/keys # API-Schlüssel, nicht im Dump; Medien bewusst NICHT offsite (zu groß)
      notes: Komodo-Procedure momentary-backup, täglich 02:00; dazu ein Dump vor jedem Deploy. Je Anlass bleiben 14.
    notes: >-
      Komodo-Stack momentary auf 10.0.0.2:8194, gestartet leer (nie zuvor in Betrieb). Jellyfin läuft nur intern
      (Export-Ziel der Pipeline), kein SFTP. Hub (momentary-hub) hängt am Netz momentary_momentary-network.
      Whisper ist CPU-lastig.
```
