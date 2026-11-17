# Fotos, Medien & Ereignisse

## Datenmodell

Videos und Fotos erben von der gemeinsamen Basis **`Medium`** (Doctrine Class Table Inheritance, Tabellen
`medium`, `video`, `photo`; Diskriminator `type`). Die ID ist über alle Medien eindeutig.

Am Medium hängt alles, was für beide Typen gilt:

| Feld | Bedeutung |
|---|---|
| `title`, `createdAt` | Titel, Importzeitpunkt |
| `takenAt` | Aufnahmezeitpunkt (UTC), Grundlage für Sortierung und Ereignisse; `getDate()` fällt auf `createdAt` zurück |
| `owner`, `isPublic`, `allowedGroups` | Rechte (siehe unten) |
| `tags` | Tags am ganzen Medium (Videos haben zusätzlich Tags je Szene) |
| `faces` | erkannte Gesichter (`VideoFace`, Name historisch; `getVideo()`/`getPhoto()` je nach Typ) |
| `events` | Ereignisse, zu denen das Medium gehört |

Die Migration `Version20261018140000` übernimmt bestehende Videos: Video-ID wird Medium-ID, Titel/Rechte/Tags
wandern nach `medium`, `video_face.video_id` heißt jetzt `medium_id`.

## Upload & Import-Ordner

Alle Wege laufen über `MediaImporter`: Typ aus der Dateiendung (Videos: mp4, mov, mkv, mts, …; Fotos: jpg, png,
heic), Doppelte per SHA-256 über Videos und Fotos hinweg, Eigentümer, Gruppen-Tag (Kategorie „Upload-Ordner“).

- **Upload** (`/upload`, Knopf in der Navigation): Dateien oder ganze Ordner, Videos und Fotos gemischt. Der Browser
  berechnet vorab den Hash und überspringt vorhandene Dateien ohne Upload. Hochgeladene Videos gehören jetzt wie Fotos
  dem, der sie hochlädt. `/photo/upload` leitet hierher um.
- **Import-Ordner** (`storage/imports`, per SFTP `upload/import/`; *Verwalten → Import-Ordner*, `ImportFolder`): für
  große Mengen und ganze Speicherkarten, auch mit Unterordnern; der erste Unterordner wird zum Gruppen-Tag. Übernommen
  wird im Hintergrund (`ImportMediaFileMessage`, Queue `async`). Übernommene Dateien verschwinden aus dem Ordner,
  per Hash erkannte Doppelte wandern nach `_doppelt/` (nichts wird ungefragt gelöscht).
  - Dateien, die in den letzten 2 Minuten geändert wurden, gelten als „wird noch hochgeladen“.
  - „Vermutlich schon vorhanden“: gleiche Größe wie ein importiertes Original oder Zeitstempel im Dateinamen gleich der
    Kamerazeit eines Mediums (`KnownMedia`). Nötig, weil ältere Videos keinen Hash haben und als Original nur noch die
    konvertierte Datei. Solche Dateien sind nicht vorausgewählt und werden nie automatisch übernommen.
- **Automatisch** (`IMPORT_FOLDER_WATCH=1`, Standard aus): Der Symfony Scheduler (`src/Schedule.php`, Transport
  `scheduler_default` im `messenger-worker`) prüft alle 5 Minuten und übernimmt fertige Dateien ohne Eigentümer
  (sichtbar für Admins, bis sie freigegeben werden). Von Hand: `bin/console app:media:import-folder`.
- Der FTP-Datei-Manager verschiebt aus den SFTP-Verzeichnissen `jellyfin_uploads`/`app_uploads` in den Import-Ordner.
  Der frühere Video-Import (`/admin/video/import`, `app:bulk-import`) ist entfallen: er legte Videos ohne Datei an.

## Rechte

`MediumAccessVoter` mit `MEDIUM_VIEW`/`MEDIUM_EDIT` (die alten `VIDEO_VIEW`/`VIDEO_EDIT` gelten weiter):
Admins und Eigentümer dürfen alles, sonst sieht man öffentliche Medien und solche, die für eine eigene Gruppe
freigegeben sind. Für Listen gibt es dieselbe Regel als Abfrage: `MediumRepository::restrictToVisible()`.

## Fotos

**Import**
- Web: *Fotos → Hochladen* (mehrere Dateien auf einmal).
- Kommandozeile: `bin/console app:photo:import <Datei|Ordner>… [--owner=mail@example.com]` (Ordner rekursiv).
- Formate: JPG, PNG, HEIC/HEIF (iPhone). Doppelte Dateien (gleicher SHA-256) werden erkannt und übersprungen.
- Ablage: `storage/photos/<id>/original.<ext>`, `display.jpg` (gedreht, max. 2560 px), `thumbnail.jpg` (max. 480 px).

**Analyse** (`AnalyzePhotoMessage` → Queue `photos`, vom `messenger-worker` mit niedrigster Priorität nach den
Video-Queues verarbeitet):
1. `video-analyzer/python/process_photo.py` (Pillow + pillow-heif): drehen laut EXIF, Anzeige-Fassung und Vorschau,
   Aufnahmezeit, GPS, Kamera.
2. Gesichtserkennung wie bei Video-Frames (`FrameAnalyzer::analyzeImage()`), also dieselben Personen über Videos und
   Fotos hinweg. Die Anzeige-Fassung dient direkt als Gesichtsbild (keine Kopie).
3. Gemini: Titel (ersetzt nur Kamera-Dateinamen wie `IMG_1234` oder `20250319_133537`) und Tags.
4. Suchindex `photos` (Meilisearch). Der Index `videos` und die Such-API `/api/search` bleiben unverändert, weil
   externe Clients sie nutzen.

Fehler stehen am Foto; *Neu analysieren* (Fotoseite) bzw. `bin/console app:photo:reanalyze <ids>|--failed`
wiederholt die Analyse und räumt dabei alte Gesichter (auch in AWS) und dadurch leere Personen auf.

### Serienfotos

Kameras liefern oft mehrere fast gleiche Bilder kurz hintereinander. Zeitachse, Ereignisseite und Fotoliste zeigen
davon nur das beste Foto mit einem Knopf „▣ 3“, der die übrigen aufklappt (Stimulus-Controller `burst`); bei der
Fotosuche zählt jeder Treffer einzeln.

- **Merkmale** (`process_photo.py`, bei der Analyse): Fingerabdruck aus 16x16 Graustufen (weichgezeichnet, normiert)
  und HSV-Farbhistogramm, dazu die Schärfe (Varianz des Laplace-Filters). Vergleich: `PhotoFingerprint`, Mittel aus
  Korrelation und Histogramm-Schnittmenge. Gemessen an den Mallorca-Fotos: gleiche Szene ≥ 0,64, verschiedene
  Motive ≤ 0,45. Ein einfacher Bild-Hash (dHash) trennte bei der verwackelten Action-Cam nicht zuverlässig.
- **Serie** (`BurstGrouper`, `PhotoBursts`): gleicher Eigentümer, höchstens 10 s nach dem vorigen Foto, Ähnlichkeit
  ≥ 0,6. Bestes Foto: von Hand gewählt, sonst die meisten Gesichter, dann das schärfste. `Photo::burstCover` zeigt
  auf das beste Foto. Nach jeder Foto-Analyse und nach dem Löschen wird die Stunde um das Foto neu geordnet.
- **Fotoseite**: alle Fotos der Serie; *Als bestes Foto verwenden* und *Aus der Serie lösen* (bzw. wieder zulassen).
- Für vorhandene Fotos: `bin/console app:photo:bursts` (berechnet fehlende Fingerabdrücke aus der Anzeige-Fassung und
  bildet alle Serien neu).

## Web-Fassung von Videos

Die Originale (Action-Cam: 4K mit 25–90 Mbit/s) passen nicht durch den Upload eines Heimanschlusses. Für das Ansehen
von unterwegs (Hub auf dem Tablet, Share-Links, Casten bei Verwandten) erzeugt die Pipeline nach dem Jellyfin-Export
eine **Web-Fassung** (`Video::webFile`, `videos/<id>/web.mp4`): kürzere Seite höchstens 1080 Pixel, H.264 mit etwa
4 Mbit/s, AAC Stereo, Faststart (`WebVersionEncoder`, Format in `WebVersionFormat`).

- Ist das Original schon klein genug (höchstens 1080p und 6 Mbit/s), gibt es keine Web-Fassung.
- Kodiert wird mit der Intel-Grafik (VAAPI), wenn `/dev/dri` im Container verfügbar ist, sonst mit libx264 auf der CPU
  (hier etwa halbe Echtzeit, auf dem ThinkCentre grob 1,5-fache Echtzeit). Für die Grafik dem `messenger-worker` in
  `compose.yaml` `devices: ["/dev/dri:/dev/dri"]` geben (Treiber `iHD` sind im Image).
- Scheitert der Schritt, ist das Video trotzdem fertig; gestreamt wird dann das Original.
- Signierte Stream-URLs und Share-Links liefern standardmäßig die Web-Fassung (`documentation/clients/API.md`).
- Für vorhandene Videos: `bin/console app:video:web-versions [ids] [--force]` (über die Queue `photos`, also nach
  neuen Videos).

## Aufnahmedatum von Videos

Wird bei der Konvertierung gesetzt (`BetterVideoAnalyzer::probeRecordedAt()`):
1. Zeitstempel im Dateinamen (`20250319_121537`, `VID_20250319_121537`) gilt als Ortszeit (Europe/Berlin). Manche
   Action-Cams schreiben `creation_time` mit falscher Zeitzone, der Dateiname stimmt.
2. Sonst `creation_time` aus den Metadaten (z. B. iPhone, korrekt in UTC).

Für ältere Videos: `bin/console app:video:recorded-at`. Funktioniert nur, solange Datei oder Metadaten das Datum
noch enthalten; die Konvertierung behält die Metadaten seit `-map_metadata 0`.

## Aufnahmezeit korrigieren

Kamera-Uhren gehen oft falsch (Beispiel Mallorca: die Action-Cam stand auf März 2025, aufgenommen wurde im Mai 2026).
Die Abstände zwischen den Aufnahmen stimmen dann trotzdem, nur der ganze Block ist verschoben.

- Video- und Fotoseiten zeigen die Aufnahmezeit mit Herkunft (`Medium::dateSource`: EXIF, Dateiname, Video-Metadaten,
  von Hand korrigiert) und bieten *Aufnahmezeit korrigieren* (`MediumDateComponent`).
- Optional werden alle Medien eines Ereignisses um denselben Abstand verschoben (`RecordingTimeCorrector`); der
  Zeitraum des Ereignisses passt sich an. Auf der Ereignisseite gibt es dafür *Aufnahmezeiten korrigieren* mit dem
  ersten Medium als Bezug. Das ganze Ereignis darf nur dessen Ersteller oder ein Admin verschieben, und es werden nur
  Medien geändert, die man bearbeiten darf.
- Korrigierte Zeiten (`manual`) überschreibt keine spätere Analyse.

### Falsche Kamera-Uhren

- Jedes Medium kennt seine **Kamera** (`Medium::camera`; Fotos aus EXIF, Videos aus den Metadaten von iPhone/Android,
  `Mp4Converter::cameraName()`; viele Action-Cams wie die AKASO schreiben in Videos nichts) und die **Kamerazeit**
  (`Medium::cameraTakenAt`): die Zeit, wie die Kamera sie geliefert hat, auch nach einer Korrektur.
  Nachtragen für ältere Videos: `bin/console app:video:recorded-at`.
- **Abweichung merken** (Häkchen im Korrekturformular, nur Admins, weil es künftige Importe aller Benutzer betrifft):
  speichert `CameraClockOffset` mit den Kameras der verschobenen Medien („ohne Kamerakennung“ zählt als eigene
  Kamera), dem Zeitfenster in Kamerazeit und dem Abstand. Liegen die Aufnahmen laut Kamera mehr als 31 Tage
  auseinander, wird nichts gemerkt (Ausreißer zuerst einzeln korrigieren).
- **Anwenden** (`CameraClock::adjust()`): Foto-Analyse und Konvertierungsschritt von Videos verschieben neue Aufnahmen
  derselben Kamera, deren Kamerazeit im Fenster ± 3 Tage liegt (Herkunft „Kamera-Uhr korrigiert“). Von Hand
  korrigierte Zeiten bleiben. Für schon vorhandene Medien: *Verwalten → Kamera-Uhren → Auf N Medien anwenden*.
- **Hinweise** (`CameraClock::warnings()`, auf Video- und Fotoseiten): Kamerazeit vor 2000 (Uhr nach leerem Akku
  zurückgesetzt), in der Zukunft, oder eine gemerkte Abweichung passt, ist aber nicht angewendet.
- *Verwalten → Kamera-Uhren* (`/admin/camera-clocks`) zeigt unplausible Aufnahmezeiten, die gemerkten Abweichungen
  und alle Kameras mit ihrem Zeitraum laut Kamera-Uhr.

## Suche

`/search` (Lupe in der Navigation) durchsucht Videos und Fotos gemeinsam (`MediaSearch`, eine Meilisearch-
Multi-Search-Anfrage an die Indizes `videos` und `photos`):
- Freitext über Titel, Tags, Personen und das Gesprochene (Transkript); Filter für Personen und Tags (alle müssen
  vorkommen), angeboten als Facetten.
- Treffer, die der Benutzer nicht sehen darf, werden weggelassen; auch die Facetten zählen nur sichtbare Treffer,
  damit keine Namen aus fremden privaten Medien auftauchen.
- Zu Videos werden Fundstellen mit Sekunde gezeigt (gesprochener Satz, Szene mit passender Person bzw. passendem Tag);
  ein Klick springt im Video an die Stelle (`?t=`). Suchbegriffe ab drei Zeichen werden hervorgehoben.
- Die externe Video-API `/api/search` und der Admin-„Search Explorer“ sind davon unabhängig.

## Zeitachse

`/timeline` (Menüpunkt „Zeitachse“) zeigt alle sichtbaren Videos und Fotos nach Aufnahmezeit, neueste zuerst
(`MediaTimeline`):
- Gruppiert nach Monat und Tag in deutscher Zeit; Medien eines Ereignisses stehen in einer Klammer mit dessen Titel.
  Gehört ein Medium zu mehreren Ereignissen, zählt das mit dem kürzesten Zeitraum.
- Filter nach Person (auch über „In der Zeitachse“ auf der Personenseite) und nach Medientyp.
- Geblättert wird mit einem Cursor (`?before=<Unix-Zeit>.<ID>`) statt Seitenzahlen; neu importierte Medien
  verschieben so nichts. Beim Scrollen lädt der Stimulus-Controller `infinite-scroll` die nächste Seite
  (`?partial=1`); ohne JavaScript bleibt der Link „Ältere laden“.
- Die Monatsleiste springt per Cursor an das Ende eines Monats.

## Ereignisse

`Event` (Tabelle `media_event`): Titel, Zeitraum, Beschreibung, Eigentümer, beliebig viele Medien.
- *Ereignisse → Neues Ereignis*: optional werden direkt alle sichtbaren Medien des Zeitraums übernommen
  (`EventMediaCollector`, Tage in deutscher Zeit, letzter Tag inklusive).
- Auf Video- und Fotoseiten lässt sich ein Medium einem Ereignis hinzufügen (`MediumEventsComponent`).
- Die Ereignisseite zeigt alle Medien nach Aufnahmezeit sortiert und die benannten Personen, die vorkommen.
- Bearbeiten nur durch Ersteller oder Admin; Besucher sehen nur Medien, die sie sehen dürfen.

### Ereignis-Vorschläge

Oben auf `/event/` schlägt `EventSuggester` Ereignisse vor: sichtbare Medien ohne Ereignis, nach Aufnahmezeit
gruppiert. Eine Lücke ohne Aufnahme (Standard 24 h, auf der Seite zwischen 6 h und 3 Tagen wählbar) oder ein
Ortswechsel über 100 km zwischen zwei GPS-Fotos beginnt eine neue Gruppe; Gruppen ab 3 Medien werden vorgeschlagen.

- *Übernehmen*: Formular mit Zeitraum und einem Titel, den Gemini aus Tags und Medientiteln vorschlägt (ein Aufruf,
  erst beim Klick); das Ereignis bekommt genau die Medien des Vorschlags.
- *Verwerfen*: wird pro Benutzer gespeichert (`EventSuggestionDismissal`). Der Schlüssel hängt an den enthaltenen
  Medien; kommen neue dazu, erscheint der Vorschlag neu.

## Orte & Karte

- **Aufnahmeort** (`Medium::latitude/longitude`): bei Fotos aus EXIF, bei Videos aus den Metadaten (ISO 6709,
  `com.apple.quicktime.location.ISO6709` bzw. `location`; iPhone und viele Android-Geräte). Action-Cams ohne GPS
  liefern nichts.
- **Ortsname** (`Medium::placeName`, z. B. „Cala d'Or, Spanien“) per Reverse-Geocoding über OpenStreetMap Nominatim
  (`ReverseGeocoder`, `PlaceTagger`): Koordinaten werden vor dem Versand auf ~100 m gerundet, Ergebnisse ein Jahr
  gecacht, höchstens eine Anfrage pro Sekunde. Ort und Land kommen zusätzlich als Tags „Orte“ ans Medium und damit in
  Suche und Ereignis-Vorschläge. Abschaltbar mit `GEOCODING_ENABLED=0` (in Tests immer aus);
  `NOMINATIM_URL` erlaubt eine eigene Nominatim-Instanz. Nachträglich: `bin/console app:media:places`.
- **Karte** `/map` (Menüpunkt „Karte“): Leaflet mit OpenStreetMap-Kacheln (über Importmap lokal ausgeliefert; die
  Kacheln lädt der Browser von OpenStreetMap), gruppierte Marker, Vorschau mit Link im Popup, Filter nach Person und
  Medientyp. Daten: `/map/points` (nur sichtbare Medien; Filter `event`, `medium`, `person`, `type`).
- Eingebettete Karten auf Ereignis-, Foto- und Videoseiten (Stimulus-Controller `media-map`).

## Personen benennen

Alles Zusammenführen und Benennen läuft über `PersonMerger`: Gesichter werden nur umgehängt (die Sammlung hat
`orphanRemoval`), die alte Person bleibt als `merged` mit Verweis erhalten, danach werden die betroffenen Videos und
Fotos neu indexiert. Wer einen Namen vergibt, den es schon gibt (Groß-/Kleinschreibung egal), führt automatisch mit
dieser Person zusammen.

- **Doppelte Personen** (`/admin/person-duplicates`, Menü Identifizieren; `DuplicatePersonFinder`): Paare aus den bei
  der Analyse gespeicherten Rekognition-Kandidaten (`VideoFace.matchCandidates`) ab der Kandidaten-Schwelle der
  Gesichtserkennung. Nicht vorgeschlagen werden Personen, die zusammen in einem Bild vorkommen, zwei benannte Personen
  und Paare, die als „Verschiedene Personen“ markiert wurden (`PersonPairDismissal`). Erhalten bleibt die benannte
  bzw. die Person mit mehr Gesichtern.
- **Wer ist dabei?** (Ereignisseite, nur Admins, weil Personen für alle Medien gelten; `EventPeople`): unbenannte
  Personen des Ereignisses mit größtem Gesicht als Beispiel, Namensfeld mit Vorschlägen bekannter Namen, *Unbekannt*
  für Fremde im Hintergrund.
- **Namenshinweise** (`PersonNameHints`): Tags der Kategorie „Erwähnte Personen“ (aus dem Transkript) in Szenen, in
  denen die Person zu sehen ist, nach Anzahl der Szenen. Ein Klick übernimmt den Namen (Ereignisseite und
  Personendetail). Nur ein Hinweis: wer gerade spricht oder angesprochen wird, ist nicht unbedingt die Person im Bild.
