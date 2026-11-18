# Wünsche der Clients an die API

Hier tragen Sitzungen in `momentary-hub` und `momentary-android` ein, was sie vom Backend brauchen. Umgesetzt wird im
Backend-Repo; danach den Eintrag als erledigt markieren und `API.md` aktualisieren.

Vorlage:

```
### <Kurztitel> (von: hub | android, Datum)
- Wozu: …
- Endpunkt/Felder: …
- Beispiel-Antwort: …
- Status: offen | erledigt (Version/Datum)
```

## Offen

### Suchindex nach Änderungen an Personen aktualisieren (von: hub, 21.10.2026)
- Wozu: Die Suche nach „Wolf“ im Hub fand nichts, obwohl Wolf (Person 31) in Szenen der Videos 4, 6–10 vorkommt.
  Ursache: Der Videos-Index (Meilisearch) war vom 18.10. und enthielt bei keinem Video Personen. Neu indexiert wird nur
  in `VideoSearchIndexSubscriber` nach einer Nachricht der Warteschlange – nicht, wenn eine Person benannt, umbenannt,
  zusammengeführt oder ein Gesicht neu zugeordnet wird. Nach `app:meilisearch:index-all` findet `q=Wolf` und
  `persons[]=Wolf` das Video sofort.
- Endpunkt/Felder: keine API-Änderung. Nach jeder Änderung an Personen/Gesichtern die betroffenen Videos neu
  indexieren (z. B. Doctrine-Listener auf `Person`/`VideoFace` oder Nachricht `ReindexVideos(ids)`); dasselbe bei
  Änderungen an Szenen-Tags, Titel und Sichtbarkeit, falls dort auch nicht. Bis dahin auf dem Server nach dem
  Benennen `app:meilisearch:index-all` (live siehe deployment.md).
- Status: offen

### Größeres Vorschaubild in `/api/search` (von: hub, 21.10.2026)
- Wozu: Die neuen Karten im Hub sind bis zu 400 px breit (auf Tablets mit doppelter Pixeldichte 800 px). `thumbnailUrl`
  kommt in `/api/search` nur als 160×90 und wirkt hochskaliert unscharf. Der Hub nimmt bei sichtbaren Videos ersatzweise
  das Bild der mittleren Szene (320×180).
- Endpunkt/Felder: in `/api/search` zusätzlich `posterUrl` (z. B. 640×360, bei `blur: 5` ebenfalls unscharf), oder
  `thumbnailUrl` gleich in dieser Größe.
- Status: offen

### Benannte Personen in `/api/persons` (von: hub, 21.10.2026)
- Wozu: Wolf (Person 31) hat einen Namen, aber Status `new`, und fehlt deshalb in `/api/persons` (liefert nur
  `identified`) – also auch in der Personenauswahl des Hubs. Offenbar setzt nicht jeder Weg zum Benennen den Status
  auf `identified` (`PersonResolverComponent` tut es, Zeile 310).
- Endpunkt/Felder: entweder beim Benennen immer `identified` setzen (und vorhandene Daten einmal nachziehen) oder
  `/api/persons` auf „hat einen echten Namen“ (nicht `unknown_…`/`split_…`, nicht `wasted`/`merged`) umstellen.
- Status: offen

### Stream-URL für öffentliche Szenen (von: hub, 21.10.2026)
- Wozu: `/api/search` liefert Videos, die der Benutzer nicht sehen darf, mit `blur: 5` und den Fundstellen ihrer
  öffentlichen Szenen. Abspielen kann der Hub diese Szenen nicht: `GET /api/videos/{id}/stream-url` prüft `VIEW` auf
  das ganze Video und antwortet mit 403. Früher ging das über `playbackUrl` mit Jellyfin-Key, das soll wegfallen.
- Endpunkt/Felder: z. B. `GET /api/video_scenes/{id}/stream-url` (Berechtigung `SCENE_VIEW`), Antwort wie die
  Video-Stream-URL, aber nur gültig für den Ausschnitt; dazu in `/api/search` je Fundstelle die Szenen-`id`
  (heute nur `start`/`endSeconds`). Alternativ `GET /api/videos/{id}/stream-url?scene={sceneId}`.
- Beispiel-Antwort:
  ```json
  {"url": "https://admin.momentary.wh-company.com/stream/scene/17?_expiration=…&_hash=…", "expiresAt": "…",
   "contentType": "video/mp4", "start": 6, "end": 33.17}
  ```
  Ob die URL nur den Ausschnitt ausliefert (Schnitt mit ffmpeg) oder das ganze Video mit Start/Ende, entscheidet das
  Backend – wichtig ist nur, dass der Rest des Videos nicht abrufbar ist.
- Status: offen

## Erledigt

### Command zum Anlegen normaler Benutzer (von: hub, 19.10.2026)
- Wozu: Familienmitglieder (und Testkonten) anlegen, die sich am Hub anmelden. Heute gibt es nur
  `app:admin:create`, das immer `ROLE_ADMIN` vergibt; ein Admin sieht alles und taugt nicht zum Testen der Rechte
  (API.md, Abschnitt 7).
- Endpunkt/Felder: kein API-Endpunkt, ein Console-Command im Backend, z. B.
  `bin/console app:user:create <E-Mail> [--group=<Name|ID> …] [--admin]`
  - Passwort verdeckt abfragen (nicht als Argument, damit es nicht in der Shell-Historie landet), optional
    `--password` für Skripte.
  - Ohne `--admin` nur `ROLE_USER`; `UserSettings` wie bei `app:admin:create` mit anlegen.
  - `--group` (mehrfach) fügt den Benutzer bestehenden Gruppen hinzu (wie `/admin/groups/{id}/members`).
  - Existiert die E-Mail schon: Fehlermeldung, oder mit `--reset-password` nur das Passwort neu setzen.
- Beispiel-Ausgabe: `[OK] Benutzer anna@example.com angelegt (ROLE_USER, Gruppen: Familie).`
- Status: erledigt (19.10.2026): `app:user:create <E-Mail> [--role=family|editor|admin] [--pool=<Name|ID> …]
  [--password=…] [--reset-password]`. Statt `--group`/`--admin` heißen die Optionen `--pool` (Gruppen sind jetzt
  Pools) und `--role` (Standard `family` = nur `ROLE_USER`). `UserSettings` werden nicht angelegt; sie werden
  nirgends gelesen, auch Konten aus Verwalten → Benutzer haben keine. Siehe `API.md` Abschnitt 3.

### Signierte Stream-URLs für Videos (von: hub/android, 18.10.2026)
- Wozu: Videos im Hub abspielen und auf den Fernseher casten, ohne den Jellyfin-API-Key herauszugeben.
- Endpunkt/Felder: z. B. `GET /api/videos/{id}/stream-url` → `{"url": "…?exp=…&sig=…", "expiresAt": "…"}`; die URL
  funktioniert ohne Cookies und mit Range-Anfragen. Optional Startsekunde für Szenen.
- Status: erledigt (18.10.2026): `GET /api/videos/{id}/stream-url`, siehe `API.md` Abschnitt 6. Offen bleibt die
  Umstellung in Hub und App; danach entfernt das Backend `playbackUrl` mit Key.
