# Gesichtserkennung

Standard ist die selbst gehostete Erkennung mit **InsightFace** (`buffalo_l`: SCRFD-Detektion, ArcFace-Embeddings,
Alter/Geschlecht) im eigenen Container `face-service`. **AWS Rekognition** bleibt umschaltbar, kostet aber pro Aufruf
und braucht gültige AWS-Zugangsdaten. Umgeschaltet wird mit `FACE_RECOGNITION_PROVIDER` (`insightface` |
`rekognition`).

> **Lizenz:** Die vortrainierten InsightFace-Modelle sind nur für nicht-kommerzielle Nutzung freigegeben
> (`face-service/README.md`).

## Architektur

```
Pipeline (Schritte 7–9, 14–16)          face-worker (1 Consumer)              face-service (Python, 2 CPUs)
AnalyzeFrame-Message ─────► Queue face_recognition ─► FrameAnalyzer ── HTTP multipart ──► /analyze (/detect)
                                                       │                                     SCRFD + ArcFace
                                                       ├─ InsightFaceProvider ◄── Boxen, Embeddings
                                                       │    └─ EmbeddingIndex: Kandidaten (Kosinus) in PHP
                                                       ├─ FaceAssigner: Person / Vorschlag / neu
                                                       └─ FaceRecorder: VideoFace + Gesichtsbild speichern
Schritt 17: VideoFaceConsolidator gruppiert alle Gesichter des Videos
```

| Teil | Aufgabe |
|---|---|
| `face-service/` | FastAPI-Dienst, nur im Docker-Netz. Filtert vor dem Embedding (`min_face_px`, `max_faces`), eine Inferenz zur Zeit. |
| `FaceServiceClient` | HTTP-Client, Bilder per multipart (kein Zugriff des Dienstes auf den Medienspeicher). |
| `FaceRecognitionProviderInterface` | `InsightFaceProvider`, `RekognitionProvider`; Auswahl über `FaceRecognitionProviders`. |
| `EmbeddingIndex`, `ReferenceSelector` | Abgleich neuer Embeddings gegen Referenzgesichter (siehe unten), Ergebnis als Kandidaten `faceLabel => Kosinus × 100`. |
| `FrameAnalyzer` | Frame laden und prüfen, Anbieter aufrufen. |
| `FaceAssigner` | Abgleich: Person ab „Sofort zuordnen“, sonst neue Person; Vorschlag einer benannten Person ab „Vorschlagen“. |
| `FaceRecorder`, `FaceImageStore` | Speichern: Gesichtsbild (ganzer Frame unter `images/faces/<mediumId>/`), `VideoFace` mit Box, Score, Größe, Embedding (float32, 2 KB), Kandidaten. |
| `VideoFaceConsolidator` | Pipeline-Schritt 17: gruppiert alle Gesichter eines Videos (niedrigere Schwelle, Tracking), nie zwei Gesichter desselben Frames. |
| `DeletedMediumGuard` | Video/Foto während der Erkennung gelöscht: Abbruch ohne Reste. |
| `FaceForgetter` | Gelöschte Gesichter beim Anbieter vergessen; AWS nur bei Rekognition-Gesichtern, Fehler blockieren nie. |

Die Zuordnung (`FaceMatcher`, Konsolidierung, Duplikatsuche) arbeitet mit beiden Anbietern gleich: Sie kennt nur
`faceLabel` und die gespeicherten Kandidaten. Jedes Gesicht merkt sich seinen Anbieter (`video_face.provider`).

### Ablauf pro Frame

1. Video noch da? Sonst `VideoGoneException` (Message wird still verworfen). Frame-Datei fehlt, weil das Video gerade
   gelöscht wird: ebenso.
2. Optional Vorfilter (`FACE_PREFILTER_DET_SIZE`, z. B. 320): erst prüfen, ob überhaupt ein Gesicht da ist. Standard
   **aus**: spart bei Homevideos nur etwa 20 % und übersieht kleine Gesichter (Messung siehe unten).
3. `/analyze`: Gesichter unter der Mindestgröße und über „Gesichter pro Bild“ bekommen kein Embedding.
4. Je Gesicht Kandidaten aus dem `EmbeddingIndex`, dann `FaceAssigner`; Gesichter desselben Frames schließen sich
   gegenseitig aus.
5. Nach dem (Sekunden dauernden) Aufruf erneut prüfen, ob das Video noch existiert; beim Speichern einen
   Fremdschlüssel-Fehler als „Video gelöscht“ werten und Gesichtsbild, Datei-Eintrag und neue Personen entfernen.

Fotos laufen über denselben `FrameAnalyzer`, aber in der Queue `photos` (messenger-worker). Sie warten am Lock des
face-service höchstens auf einen laufenden Video-Frame.

### Referenzen einer Person

Verglichen wird nicht mit allen Gesichtern, sondern je Person mit höchstens `FACE_MATCH_REFERENCES_PER_PERSON` (20)
Referenzen plus allen Gesichtern des gerade analysierten Mediums:

- **Qualität:** Referenz wird nur, wer sicher genug erkannt (Detektions-Score ab „Referenz ab Detektions-Score“, 0,7)
  und groß genug ist („Referenz ab Größe“, 60 px, in nativen Pixeln). Verifizierte Gesichter zuerst. Schlechte
  Aufnahmen streuen stark; sie werden zugeordnet, prägen aber nicht, wie die Person aussieht. Hat eine Person kein
  einziges gutes Gesicht, bleiben ihre 3 besten Referenz.
- **Über Jahrzehnte:** Kinder wachsen auf. Die Referenzen werden nach Aufnahmejahr gruppiert und reihum gefüllt (das
  beste Gesicht jedes Jahres, dann das zweitbeste …), Jahre nahe am analysierten Video zuerst. Gesichter ohne
  Aufnahmezeit zählen neutral (eigene Gruppe in der Mitte). Bevorzugt wird nur über die Auswahl, die Ähnlichkeit wird
  nicht gewichtet; die Schwellen behalten ihre Bedeutung.

## Konfiguration

**Admin → Gesichtserkennung** (`/admin/settings/face-recognition`, Tabelle `face_recognition_settings`), wirkt sofort.
Die Seite zeigt nur die Werte des eingestellten Anbieters; die des anderen bleiben gespeichert.

| InsightFace | Standard | |
|---|---|---|
| Mindestgröße | 40 px | kürzere Box-Seite, bezogen auf die native Auflösung (siehe `sourceHeight`) |
| Gesichter pro Bild | 10 | größte zuerst, 0 = alle |
| Referenz ab Detektions-Score / Größe | 0,7 / 60 px | siehe oben |
| Kandidaten ab | 0,25 | Kosinus; muss unter allen anderen Schwellen liegen |
| Im Video gruppieren ab | 0,35 | Konsolidierung innerhalb eines Videos |
| Vorschlagen ab | 0,38 | benannte Person als Vorschlag am Gesicht |
| Sofort zuordnen ab | **0,50** | videoübergreifend; bewusst hoch: lieber ein Vorschlag mehr als eine falsche Zuordnung |
| Geschlecht beachten | aus | die Schätzung liegt bei Kindern und schlechten Aufnahmen oft daneben |

Rekognition hat eigene Felder (Qualitätsfilter, Mindestgröße als Anteil der Bildbreite, Schwellen in Prozent).
Tracking und „Kandidaten pro Gesicht“ gelten für beide.

Umgebung (`.env.example`, `.env.prod.dist`, `momentary.toml`):

| Variable | Standard | |
|---|---|---|
| `FACE_RECOGNITION_PROVIDER` | insightface | oder `rekognition` |
| `FACE_SERVICE_URL`, `FACE_SERVICE_TIMEOUT` | http://face-service:8000, 60 s | Timeout schließt das Warten auf eine laufende Anfrage ein |
| `FACE_PREFILTER_DET_SIZE` | 0 | Vorfilter aus |
| `FACE_MATCH_REFERENCES_PER_PERSON` | 20 | |
| `FACE_DET_SIZE`, `FACE_MIN_DET_SCORE` | 640, 0,5 | face-service |
| `FACE_INTRA_OP_THREADS`, `FACE_INTER_OP_THREADS` | 2, 1 | face-service |
| `FRAME_ANALYSIS_FPS`, `REFINED_FRAME_ANALYSIS_FPS` | 0,2, 1,0 | Pipeline; ist die erste ≥ der zweiten, entfällt das Refinement |

## Ressourcen und Start

`face-service`: höchstens 2 CPUs und 2 GB (`deploy.resources.limits`), `cpu_shares: 512` (gibt bei Konkurrenz den
anderen Projekten auf abyss den Vortritt), Healthcheck, kein Port nach außen. Modelle (~280 MB) beim ersten Start ins
Volume `face_models`. `face-worker`: genau ein Consumer der Queue `face_recognition`, `nice 10`.
Gemessen auf abyss (i5-6500T, 2 CPUs): Detektion 640 ≈ 200 ms, Embedding ≈ 200 ms je Gesicht.

```bash
docker compose up -d --build face-service face-worker      # Entwicklung
docker compose logs -f face-service                         # "Modell buffalo_l/w600k_r50 geladen"
docker compose exec face-service python bench.py            # Laufzeit des Dienstes (face-service/README.md)
```

## Messen und Kalibrieren: `app:face:analyze`

```bash
bin/console app:face:analyze <videoId>                       # nur messen, nichts speichern
bin/console app:face:analyze <videoId> --fps 1 --frames      # andere Bildrate, Tabelle aller Frames
bin/console app:face:analyze <videoId> --two-stage[=320]     # Vorfilter zusätzlich messen: ein- vs. zweistufig
bin/console app:face:analyze <videoId> --mode scenes         # ein Frame je Szene
bin/console app:face:analyze <videoId> --limit 50
bin/console app:face:analyze <videoId> --save                # Gesichter des Videos ersetzen und konsolidieren
```

Ausgabe: Laufzeit je Frame (detect, embed, Warten, Abgleich), Gesichter und gefilterte Gesichter, Summen, beim
Vorfilter die Ersparnis und die verpassten Gesichter. Dazu die **Verteilung der besten Ähnlichkeit** je Gesicht zu einer
vorhandenen Person (0,05-Schritte) und wie viele Gesichter bei den aktuellen Schwellen **zugeordnet, vorgeschlagen
oder unbekannt** wären. Vorgeschlagen werden nur benannte Personen. Verwendet immer InsightFace, nie AWS.
`--save` fragt interaktiv nach; mit `--no-interaction` wird ausgeführt.

Kalibrieren: ein Video mit bekannten, benannten Personen messen. Liegen viele richtige Treffer knapp unter „Sofort
zuordnen“, die Schwelle vorsichtig senken; liegen falsche Personen darüber, anheben. Bestehende Videos danach ohne
neue Analyse neu gruppieren: `bin/console app:faces:consolidate --all` (oder auf der Einstellungsseite).

## Material: alte Homevideos

momentary ist für VHS, Hi8, MiniDV und Ähnliches gedacht: meist SD (720×576), oft interlaced, verrauscht, mit
nicht-quadratischen Pixeln.

- **Interlacing** (`Mp4Converter::detectFieldOrder`): erkannt über `field_order`, bei `unknown` mit dem ffmpeg-Filter
  `idet`. Am Video gespeichert (`Video::fieldOrder`, `isInterlaced()`), für eine spätere KI-Aufbereitung.
  Konvertierung und damit Web-Fassung: interlaced Material wird immer neu kodiert, `bwdif=mode=send_field` (aus 50i wird
  50p, die flüssige Bewegung bleibt). Analyse-Frames: zusätzlich `bwdif` für als interlaced markierte Bilder.
  Interlaced Originale löscht `app:video:cleanup-originals` nicht (außer mit `--include-interlaced`).
- **Frames** (`video-analyzer/python/extract_frames.py`): Seitenverhältnis entzerrt (SAR, nur die Breite, immer
  gerade), nie verkleinert außer auf höchstens 1920 px lange Seite, JPEG `-q:v 2`.
- **Native Auflösung** (`Video::sourceHeight`): beim ersten Verarbeiten per ffprobe; für schon hochskaliert importierte
  Videos `bin/console app:video:source-height <id> 576`. Mindestgröße und gespeicherte Gesichtsgröße beziehen sich
  darauf: Hochskalierte Pixel enthalten keine zusätzlichen Details.

Vergleich an einem künstlichen PAL-Band (720×576, anamorph, 50i, aus einem Handy-Video mit drei Personen), 10 Frames,
gemessen gegen dieselben Gesichter im HD-Original: alte Kette 4 Gesichter, Score-Median 0,79, Ähnlichkeit zum
HD-Gesicht Median 0,73 (min. 0,47); neue Kette 6 Gesichter, 0,83, 0,75 (min. 0,61).

## Designentscheidungen

- **Analyse immer auf dem Original.** Szenen, Gesichter, Kapitel, Metadaten und Aufnahmezeit werden aus dem
  digitalisierten Original berechnet, das zuerst hochgeladen wird.
- **Nie auf gesichts-restauriertem Material.** Face-Restoration-Modelle (GFPGAN, CodeFormer, wie in HitPaw/VikPea)
  erfinden Gesichtsdetails und verfälschen die Identität. Erkannt wird auf dem Original oder höchstens auf nur
  allgemein hochskaliertem/entrauschtem Material.
- **Aufbereitete Fassung nur für die Wiedergabe.** Eine extern aufbereitete Datei kommt später als zusätzliche
  Wiedergabe-Datei desselben Videos (Web, Jellyfin); sie ersetzt nicht die Quelldatei und wird nie für die
  Gesichtserkennung genutzt. Code darf nicht voraussetzen, dass analysierte und exportierte Datei dieselbe sind.
- **Synchronität wird beim Anhängen geprüft.** Weil alle Zeitpunkte vom Original stammen, muss die aufbereitete Fassung
  deckungsgleich sein: Dauer (Toleranz ~2 Frames), normalisierte Startzeit, Schnitte der Szenenerkennung gegen die
  des Originals (`Video::sceneCuts`, rohe Schnitte vor dem Zusammenlegen leerer Szenen). Konstanter Versatz: melden,
  optional speichern; Drift oder fehlende Schnitte: ablehnen. Eine andere Bildrate ist erlaubt. (Das Anhängen ist
  noch nicht gebaut, siehe `TODO.md`.)
- **Dedupe aufeinanderfolgender Frames gibt es bewusst nicht:** Das Embedding ist dann schon berechnet, und Tracking,
  Konsolidierung und die Zeitabschnitte je Person in der Suche brauchen jede Sichtung.

## Löschen

`VideoDeleter` (und `PhotoDeleter`, `PersonDeleter`): Datenbank per Cascade, Ordner `videos/<id>`, `frames/<id>`,
`images/faces/<id>`, leere automatische Personen, Gesichter beim Anbieter (`FaceForgetter`). Läuft für das Video noch
ein Frame-Job, bricht er ohne Reste ab (siehe Ablauf).

## Rekognition (optional)

`FACE_RECOGNITION_PROVIDER=rekognition` und `MOMENTARY_AWS_*`. Ablauf: `indexFaces` legt alle Gesichter in der
AWS-Collection an, `searchFaces` liefert die Kandidaten. Collection anlegen: `app:rekognition:setup`. Embeddings von
Rekognition und InsightFace sind nicht vergleichbar; ein Wechsel heißt, Videos neu zu analysieren.
