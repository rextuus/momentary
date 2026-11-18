# Momentary – API-Vertrag für Clients

**Verbindliche Beschreibung der Schnittstelle**, die `momentary-hub` (Web-Kiosk) und `momentary-android` (Hülle um den
Hub) vom Backend `momentary` nutzen. Dieses Dokument liegt im Backend-Repo und wird dort gepflegt; ältere Dokumente in
den Client-Repos (z. B. `momentary-hub/documentation/MASTER_STATUS.md`) beschreiben einen veralteten Stand.

Stand: 18.10.2026 (Backend: Symfony 8.1, API Platform, PHP 8.5).

---

## 1. Die drei Projekte

| Projekt | Ordner | Rolle | Port (lokal) |
|---|---|---|---|
| **momentary** | `../momentary` | Backend: Import, Analyse (Gesichter, Szenen, Tags, Transkript), Web-Oberfläche zum Pflegen, REST-API | 8088 |
| **momentary-hub** | `../momentary-hub` | Kiosk-Frontend (Symfony, Twig, Tailwind, Hotwire Turbo), zustandslos, keine eigene Datenbank | 8089 |
| **momentary-android** | `../momentary-android` | Hotwire-Native-App, zeigt den Hub im WebView, castet Videos per Google Cast | – |

Weitere Dienste des Backends (lokal `momentary/compose.yaml`, live `compose.prod.yaml`): imgproxy (Bilder; lokal
8081, live nur über das Backend unter `/imgproxy/`), Jellyfin (Videos; lokal 8096, live nur intern), Meilisearch (Suche,
nur intern), RabbitMQ, MySQL, SFTP (nur lokal, 2222). Betrieb: `documentation/deployment.md`.

Der Hub-Container hängt im Docker-Netz des Backends (`momentary_momentary-network`) und spricht es intern als
`http://app:80` an (`MOMENTARY_API_URL`, `MOMENTARY_OAUTH2_TOKEN_URL=http://app:80/token`). Browser und Android-App
laden Bilder und Videos dagegen über **öffentliche** Adressen (`IMGPROXY_PUBLIC_HOST`, `JELLYFIN_PUBLIC_HOST`).

## 2. Datenmodell (aktuell)

Seit Oktober 2026 gibt es **zwei Medientypen** mit gemeinsamer Basis:

- `Medium` (abstrakt): Titel, Aufnahmezeit (`takenAt`), Ort, Eigentümer, Sichtbarkeit (`isPublic`, Gruppen), Tags,
  Gesichter, Ereignisse.
  - `Video`: Szenen (`VideoScene` mit Start/Ende, Tags je Szene), Kapitel, Transkript, Jellyfin-Export.
  - `Photo`: Anzeige-Fassung, Vorschaubild, Serienfotos.
- `VideoFace`: ein erkanntes Gesicht in einem Video-Frame **oder** einem Foto (Name historisch), gehört zu einer
  `Person`.
- `Person`: Name, Status (`new`, `identified`, `unknown`, `wasted`, `merged`, …), Profilbild.
- `Tag` in einer `TagCategory` (z. B. „Orte“, „Erwähnte Personen“).
- `Event`: Ereignis/Album mit beliebig vielen Medien.

**Über die API erreichbar sind bisher nur Videos, Personen, Gesichter, Tags und die Videosuche.** Fotos, Ereignisse,
Zeitachse, Karte und Share-Links gibt es nur in der Web-Oberfläche des Backends (siehe Abschnitt 8).

## 3. Anmeldung (OAuth2)

Das Backend ist OAuth2-Server (`league/oauth2-server-bundle`). Clients nutzen den **Password Grant** mit Refresh Token.

```http
POST /token
Content-Type: application/x-www-form-urlencoded

grant_type=password&client_id=momentary-hub&client_secret=…&username=<E-Mail>&password=<Passwort>&scope=email
```

Antwort: `{"token_type":"Bearer","expires_in":3600,"access_token":"…","refresh_token":"…"}`.

- Access Token 1 Stunde, Refresh Token 1 Monat (`config/packages/league_oauth2_server.yaml`).
- Erneuern: `grant_type=refresh_token&refresh_token=…&client_id=…&client_secret=…`.
- Anfragen: Header `Authorization: Bearer <access_token>`, `Accept: application/ld+json`.
- Clients verwalten: im Backend unter **Verwalten → API-Zugänge** (`/admin/settings/api-clients`): anlegen (optional mit
  eigenem Secret), neues Secret erzeugen, deaktivieren, löschen; dort stehen auch die Werte für die `.env` des Hubs.
  Secrets werden nur als Hash gespeichert und sind nur direkt nach dem Anlegen bzw. Neu-Erzeugen sichtbar.
  Alternativ per Konsole:
  `docker compose exec app bin/console league:oauth2-server:create-client "Momentary Hub" momentary-hub --grant-type=password --grant-type=refresh_token --scope=email`
- Der Benutzer ist ein normales Backend-Konto; die API wertet seine Rolle aus (Admin, Eigentümer, Pools).
  Konten anlegen unter **Verwalten → Benutzer** oder per Konsole (z. B. Testkonten ohne Admin-Rechte):
  `docker compose exec app bin/console app:user:create anna@example.com --pool=Familie` (Rolle `family` als
  Standard, `--role=editor|admin`; Passwort wird verdeckt abgefragt, leer = erzeugen; `--password` für Skripte;
  `--reset-password` setzt bei vorhandenem Konto nur das Passwort neu).
- `/api/login_check` (JWT) steht noch in der Security-Konfiguration, das JWT-Bundle ist aber **nicht aktiv**. Nicht
  verwenden.

## 4. Endpunkte

Format: JSON-LD/Hydra (`member`, `totalItems`, `@id`). Ausnahme: `/api/search` liefert einfaches JSON.
Interaktive Doku: `GET /api/docs` (Swagger UI), maschinenlesbar `GET /api/docs.jsonopenapi`; beides nur mit
Bearer-Token.

| Methode und Pfad | Anmeldung | Inhalt |
|---|---|---|
| `GET /api/videos` | ja | Fertig analysierte Videos (`status = completed`), Gruppe `video:list` |
| `GET /api/videos/{id}` | ja | Ein Video mit Szenen, Kapiteln, Gesichtern, Gruppe `video:detail` |
| `GET /api/persons` | **nein** | Benannte Personen (`status = identified`), Gruppe `person:list` |
| `GET /api/people/{id}` | ja | Eine Person, Gruppe `person:detail` |
| `GET /api/tags`, `/api/tags/{id}` | **nein** | Tags mit Kategorie; Filter `?name=` (Teilstring) |
| `GET /api/tag_categories`, `/{id}` | ja | Kategorien |
| `GET /api/video_faces`, `/{id}` | ja | Gesichter |
| `GET /api/search` | **nein** | Volltext- und Facettensuche über Videos (Meilisearch) |
| `GET /api/videos/{id}/stream-url` | ja | Signierte, befristete Stream-URL (Abschnitt 6) |
| `GET /stream/video/{id}?_expiration=…&_hash=…` | Signatur | Die MP4 selbst (Abschnitt 6) |
| `POST /token` | – | OAuth2 (siehe oben) |

### Filter auf `/api/videos`

`title` (Teilstring), `videoFaces.person.name` (Teilstring), `scenes.tags.name` (Teilstring), `scenes.tags.id`
(exakt). Die Punkte müssen **unverändert** in der URL stehen (`?videoFaces.person.name=Anna`); PHP-Clients dürfen die
Query nicht über das `query`-Array von HttpClient bauen, das macht aus Punkten Unterstriche (der Hub baut die URL
deshalb selbst, siehe `BackendApiClient::request()`).

### Beispiel `GET /api/videos`

```json
{
  "@context": "/api/contexts/Video",
  "@id": "/api/videos",
  "@type": "Collection",
  "totalItems": 1,
  "member": [
    {
      "@id": "/api/videos/1",
      "@type": "Video",
      "id": 1,
      "title": "Pool",
      "status": "completed",
      "duration": 125,
      "totalFrames": 0,
      "processedFrames": 0,
      "jellyfinItemId": "abc123",
      "createdAt": "2026-09-08T23:40:29+00:00",
      "thumbnailUrl": "https://imgproxy…/…",
      "isPublic": false
    }
  ]
}
```

`thumbnailUrl` ist eine signierte imgproxy-URL; ohne Vorschaubild steht dort der relative Platzhalter
`defaults/video-placeholder.jpg`. Die Aufnahmezeit (`takenAt`) ist bei Videos noch **nicht** in der API.

### Beispiel `GET /api/videos/{id}` (gekürzt)

```json
{
  "@id": "/api/videos/1",
  "@type": "Video",
  "id": 1,
  "title": "Pool",
  "status": "completed",
  "duration": 125,
  "jellyfinItemId": "abc123",
  "scenes": [
    {
      "id": 1,
      "sceneNumber": 1,
      "startSeconds": 0,
      "endSeconds": 12.5,
      "isPublic": false,
      "sceneTags": [{ "id": 1, "tag": "/api/tags/1", "isAiGenerated": false }],
      "playbackUrl": null
    }
  ],
  "chapters": [],
  "tags": [],
  "videoFaces": [
    { "@id": "/api/video_faces/1", "id": 1, "person": "/api/people/1", "timestamp": 5, "age": 12, "gender": "Female", "emotion": "Happy" }
  ],
  "thumbnailUrl": "…",
  "isPublic": false
}
```

`playbackUrl` ist nur bei **öffentlichen Szenen** eines nach Jellyfin exportierten Videos gesetzt (siehe Abschnitt 6).

`emotion` an Gesichtern ist optional: Die selbst gehostete Gesichtserkennung (InsightFace, Standard seit 11/2026)
schätzt keine Emotion, das Feld **fehlt** dann (Werte `null` werden nicht ausgegeben). Nur mit AWS Rekognition
erkannte Gesichter haben es. `age` und `gender` sind weiterhin immer gesetzt (`gender`: `Male`, `Female` oder
`Unknown`).

### Beispiel `GET /api/persons`

```json
{
  "member": [
    {
      "@id": "/api/people/1",
      "id": 1,
      "name": "Anna",
      "status": "identified",
      "identified": false,
      "wasted": false,
      "showCount": 0,
      "profileFaceObject": "/api/video_faces/1",
      "profileImageUrl": "https://imgproxy…/…"
    }
  ]
}
```

Unbenannte Personen heißen intern `unknown_…` bzw. `split_…`; die API liefert in der Liste nur benannte.

### `GET /api/search`

Parameter: `q` (Freitext, darf fehlen), `persons[]`, `tags[]` (alle müssen vorkommen). Höchstens 20 Treffer.
Antwort: Liste von Videos (Felder wie `video:list`, dazu):

| Feld | Bedeutung |
|---|---|
| `scenes` | alle Szenen des Videos (wie in `video:detail`) |
| `persons_with_scenes` / `tags_with_scenes` | Fundstellen: `{name, start, end, endSeconds, thumbnailUrl, …}`, bei Filtern nur die gesuchten Namen |
| `blur` | 0 = darf ansehen, 5 = Vorschaubilder unscharf (keine Berechtigung) |
| `accessInfo` | Text, z. B. „Sichtbar (Eigentümer)“, „Nicht sichtbar“ |
| `availabilityStatus` | „Öffentlich“ oder „Privat“ |

Die Sprung-Sekunde einer Fundstelle ist `start`.

## 5. Bilder

Alle Bild-URLs in API-Antworten sind **fertig signierte imgproxy-URLs** (`thumbnailUrl`, `imageUrl` am Gesicht,
`profileImageUrl` an der Person, Szenen-Vorschauen in der Suche). Clients dürfen sie nicht verändern (Signatur) und
müssen sie direkt im Browser bzw. in der App laden. imgproxy muss dafür unter `IMGPROXY_PUBLIC_HOST` vom Endgerät aus
erreichbar sein.

## 6. Videos abspielen

### Signierte Stream-URL (zu verwenden)

```http
GET /api/videos/{id}/stream-url            (Web-Fassung, falls vorhanden)
GET /api/videos/{id}/stream-url?quality=original
Authorization: Bearer …
```

```json
{
  "url": "http://192.168.178.56:8088/stream/video/4?_expiration=1792360000&_hash=…",
  "expiresAt": "2026-10-19T03:15:00+00:00",
  "contentType": "video/mp4",
  "quality": "web",
  "hasWebVersion": true,
  "duration": 85.4
}
```

- **Qualität:** Standard ist die **Web-Fassung** (höchstens 1080p, etwa 4 Mbit/s). Die Originale sind oft 4K mit
  25–90 Mbit/s und passen nicht durch den Upload eines Heimanschlusses; zum Ansehen von unterwegs (Tablet, Handy,
  Fernseher bei Verwandten) also immer die Web-Fassung nehmen. `quality=original` nur im Heimnetz oder zum Herunterladen.
  Gibt es keine Web-Fassung (Original schon klein genug oder noch nicht erzeugt), kommt das Original; `quality` in der
  Antwort sagt, was tatsächlich geliefert wird.

- `403`: der Benutzer darf das Video nicht sehen. `409`: es gibt (noch) keine abspielbare MP4. `401`: kein Token.
- Die `url` funktioniert **ohne Anmeldung, ohne Cookies und ohne Jellyfin-Key**, 6 Stunden lang, nur für dieses Video.
  Sie unterstützt Range-Anfragen (Spulen) und `HEAD` und sendet `Access-Control-Allow-Origin: *`. Damit eignet sie sich
  für `<video src>` im Hub **und** zum Casten (Google Cast lädt sie direkt vom Fernseher).
- Signatur und Ablauf sind Teil der URL; nicht verändern. Geänderte, abgelaufene oder unsignierte URLs → `403`
  (Klartext, keine Weiterleitung).
- Startsekunde (z. B. Szene aus der Suche): im Browser `url + '#t=' + sekunden` (Media Fragment, geht nicht an den
  Server), beim Casten als Startposition an `RemoteMediaClient.load(…, startMs)`.
- Die URL immer frisch holen, kurz bevor abgespielt wird; nicht speichern oder in Listen vorab erzeugen.
- Die Adresse in `url` kommt aus `APP_PUBLIC_URL` im Backend (z. B. `http://192.168.178.56:8088` oder die öffentliche
  HTTPS-Adresse). Ist sie leer, nimmt das Backend die Adresse der Anfrage; fragt der Hub intern über `http://app:80` an,
  käme dann eine für Browser unbrauchbare `http://app/…`-URL heraus. Für den Hub muss `APP_PUBLIC_URL` also gesetzt sein.

### Altlast: Jellyfin mit API-Key (wird entfernt)

Bisher baut der Hub `{JELLYFIN_PUBLIC_HOST}/Videos/{jellyfinItemId}/stream?static=true&api_key=…` und schreibt den Key
ins HTML; `playbackUrl` an öffentlichen Szenen enthält ihn ebenfalls. Der Key gibt vollen Zugriff auf Jellyfin.
**Neue Stellen nur mit der signierten Stream-URL bauen.** Sobald Hub und App umgestellt sind, entfernt das Backend
`playbackUrl` mit Key, und `JELLYFIN_API_KEY` verschwindet aus dem Hub.

## 7. Rechte (was ein Client sehen darf)

Die API zeigt genau das, was der angemeldete Benutzer auch in der Web-Oberfläche sieht (`documentation/pools.md`):
Admins alles; sonst öffentliche Medien, eigene und die der **Pools**, in denen der Benutzer Mitglied ist.

- `/api/videos`, `/api/video_faces`: nur sichtbare Medien (andere → `404`).
- `/api/persons`, `/api/people/{id}`: nur benannte Personen, die in einem sichtbaren Medium vorkommen.
- `/api/tags`: nur Tags sichtbarer Medien bzw. Szenen (Tags wie „Erwähnte Personen“ verraten sonst Namen).
- `/api/search`: nur sichtbare Videos; Videos mit öffentlichen Szenen erscheinen mit `blur: 5` und nur mit den
  Fundstellen dieser Szenen.
- Ohne Anmeldung (`/api/search`, `/api/persons`, `/api/tags` sind erreichbar): nur öffentliche Inhalte.
- Für die Familie (Mutter, Verwandte) im Hub also immer mit deren eigenem Konto anmelden; die Rechte regelt das
  Backend.

## 8. Was es (noch) nicht in der API gibt

Fotos, Ereignisse, Zeitachse (Cursor-Blättern), gemeinsame Suche über Videos und Fotos, Karte, Aufnahmezeit und Ort,
Transkripte, Share-Links. Die Web-Oberfläche des Backends hat das alles (`documentation/media.md`,
`documentation/sharing.md`). Wenn ein Client etwas davon braucht, wird es im Backend als API ergänzt (TODO-Idee G),
nicht im Client nachgebaut.

## 9. Änderungen an diesem Vertrag

- Änderungen an der API macht nur eine Sitzung im Backend-Repo; sie aktualisiert dabei dieses Dokument und die Tests
  unter `tests/Functional/Api/`.
- Braucht ein Client etwas Neues, beschreibt er es als Wunsch (Endpunkt, Felder, Beispiel) in
  `documentation/clients/WUENSCHE.md` im Backend-Repo oder gibt es dem Benutzer mit; er baut keine Umgehung im Client.
