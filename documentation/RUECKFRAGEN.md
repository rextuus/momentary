# Rückfragen

Offene Entscheidungen aus der Arbeit an Gesichtserkennung und Homevideos. Antwort gern direkt hier darunter.

## Entschieden (19.11.2026)

- **DV-Testdateien:** zwei Beispiele unter `dv_example/` (Befund in `dv_aufnahmedatum.md`), echte Kassette folgt.
- **DV auslesen:** eigener Python-Leser statt dvrescue; Kamerazeit als Europe/Berlin; Stufe 1 = Segmente, `takenAt`,
  Szenengrenzen. Gebaut (`dv_aufnahmedatum.md` → Umsetzung).
- **Früheste plausible Kamerazeit:** 1995-01-01; Werksdatum (1. Januar, erste Stunde) verwerfen; über Kassetten
  wiederkehrende Tage nur als Hinweis zur Prüfung, Datum gilt weiter. DV ohne gültige Subcodes (analoge Hi8/Video8 über Digital8) ist der
  Normalfall: Rückfall auf die bisherigen Quellen, Segment ohne Datum, von Hand nachtragbar. Beschrieben in
  `dv_aufnahmedatum.md` (6, 7); umgesetzt wird es zusammen mit dem DV-Aufnahmedatum.
- **Bestätigte Gesichter beim Neuverarbeiten:** bleiben erhalten, über Zeitpunkt und Box-IoU (`VerifiedFaceCarryover`,
  `face_recognition.md` → Neuverarbeiten). Gebaut und getestet.
- **Dev-Datenbank:** nach `mysqldump` migriert (Sicherungen unter `var/backups/`). Jede Migration vorher an einer
  Kopie testen, auch kleine.
- **Was gilt als bestätigt:** jede Zuordnung von Hand (Liste in `face_recognition.md` → Neuverarbeiten). Gebaut.
- **Ablehnungen („nicht diese Person“):** sollen ebenfalls erhalten bleiben; zurückgestellt, Plan in `TODO.md`
  (Gesichtserkennung, A).

## DV-Aufnahmedatum (Vorschlag: `dv_aufnahmedatum.md`)

1. **Echte Kassette:** kommt heute Abend (2–3 Aufnahmen hintereinander). Daran prüfen: setzt die Kamera REC ST am
   Aufnahmebeginn (angenommen: Bit = 0 heißt Beginn; unbestätigt), Datumssprung, PAL, AVI aus der Capture-Software.
   Gegenprobe mit dvrescue (lokal, `docker/dvrescue-check/`); Abweichungen werden aufgelistet.
2. **Pools/Freigaben:** Gehört eine Kassette mit Weihnachten 2005 und Urlaub 2006 dann ganz in beide Pools, oder soll es
   später Freigaben je Segment/Szene geben?

## Neuverarbeiten

3. **Szeneneinstellungen** (öffentlich, Gruppen, Tags von Hand) gehen beim Neuverarbeiten weiterhin verloren, weil die
   Szenen neu entstehen. Sollen sie ähnlich übertragen werden (über die Zeitüberlappung alter und neuer Szenen)?

## Aufbereitete Fassung

4. Beim späteren Anhängen: eigene Upload-Schaltfläche auf der Videoseite zusätzlich zum Command, oder reicht der
   Import-Ordner (`enhanced/<videoId>_….mp4`) für die großen Dateien?
