# Momentary (Backend) – Hinweise für Claude

Familien-Archiv für Videos und Fotos: Import, Analyse (Gesichter mit AWS Rekognition, Szenen, Tags mit Gemini,
Transkription mit faster-whisper), Web-Oberfläche zum Pflegen und REST-API. Der Benutzer spricht Deutsch; Oberfläche,
Kommentare und Doku sind deutsch. Doku unter `documentation/` (Einstieg: `media.md`, `TODO.md`).

## Clients

`momentary-hub` (`../momentary-hub`, Web-Kiosk) und `momentary-android` (`../momentary-android`, Hülle um den Hub)
nutzen die API. **Der Vertrag steht in `documentation/clients/API.md`.** Wer die API ändert (Felder, Gruppen,
Endpunkte, Rechte, Anmeldung), aktualisiert `API.md` und die Tests unter `tests/Functional/Api/` in derselben Arbeit.
Wünsche der Clients: `documentation/clients/WUENSCHE.md`.

## Betrieb

Entwicklung: `compose.yaml` (Code eingebunden, `documentation/setup.md`). Produktion auf dem ThinkCentre:
`compose.prod.yaml` + `make prod-*`, Secrets in `.env.prod.local` (Vorlage `.env.prod.dist`), Anleitung
`documentation/deployment.md`. Ein Image (`docker/php/Dockerfile`, Ziele `dev`/`prod`) für app und beide Worker.
Neue Umgebungsvariablen in `.env.example`, `.env.prod.dist` und `compose.prod.yaml` eintragen; Python-Pakete in
`docker/php/requirements.txt`.

## Arbeitsweise

- Alles läuft in Docker (`docker compose exec -T app …`). Tests: `docker compose exec -T app vendor/bin/phpunit`;
  Qualität: `composer cs:fix`, `composer phpstan` (Level 8, leere Baseline), `composer cs` (Mago).
- **Tests nie gegen die Dev-Datenbank oder den echten Storage**: `phpunit.xml.dist` erzwingt SQLite und
  `STORAGE_ROOT=var/storage`, `tests/bootstrap.php` bricht sonst ab. Daran nichts lockern.
- Vor riskanten Migrationen die Dev-Datenbank sichern; Migrationen erst an einer Kopie testen.
- Keine Testdaten oder Benutzer in `momentary_dev` anlegen; für Vorschauen eine Kopie der Datenbank nutzen und danach
  löschen.
- Kosten verursachende Dienste (AWS, Gemini) nur nach Rückfrage mit echten Daten aufrufen.
- Committen macht der Benutzer selbst; nur auf ausdrücklichen Wunsch.
