# Vorschlag: Aufnahmedatum aus DV-Subcodes (MiniDV, Digital8)

Stand 18.11.2026, **noch nicht gebaut**. Offene Entscheidungen: `RUECKFRAGEN.md`.

## Ausgangslage

- Bei digitalisierten MiniDV-/Digital8-Kassetten steht das echte Aufnahmedatum in jedem DV-Frame (Subcode
  „Recording Date/Time“, dazu Start-/Stopp-Kennzeichen). Dateiname und `creation_time` sind nur das Datum des
  Digitalisierens.
- Eine Kassette enthält meist viele Aufnahmen von verschiedenen Tagen; ein `takenAt` pro Video reicht nicht.
- Heute (`BetterVideoAnalyzer::probeRecordedAt`): Dateiname → `creation_time`. DV-Subcodes werden nicht gelesen.

## 1. Auslesen

| Werkzeug | Ergebnis | Ins Image? |
|---|---|---|
| **dvrescue** (MIPoPS/MediaArea) | Aufnahmedatum (`rdt`) je Frame, Sprünge (`rdt_nc`), Aufnahme-Start/-Ende (`rec_start`, `rec_end`), Timecode, PTS. Liest DV in AVI, MOV, MKV und rohes `.dv`. XML nennt nur Frames, an denen sich etwas ändert: erster Frame, jeder Sprung, letzter Frame. | Ja. Nicht in Debian, aber im MediaArea-Repository für Debian 13 (`dvrescue 24.07`, mit `libmediainfo`); im Wegwerf-Container getestet. Vorschlag: feste Version über das MediaArea-Repo oder die `.deb`-Dateien mit Prüfsumme im `Dockerfile`. |
| MediaInfo | nur „Recorded date“ des **ersten** Frames, Scan-Reihenfolge, Timecode | Ja (Debian: `mediainfo` 25.04), als schnelle Prüfung oder Rückfall |
| ffprobe/ffmpeg | kein Aufnahmedatum (nur Timecode), `field_order=unknown` | schon da |

Probelauf mit einer künstlichen Kassette (zwei DV-Aufnahmen vom 12.07.2004 und 24.12.2005 hintereinander, in AVI):

```xml
<frame n="0"  pts="00:00:00.000000" tc="00:00:00:00" rdt="2004-07-12 14:33:05" seqn="6"/>
<frame n="37" pts="00:00:01.480000" tc="00:00:00:00" tc_nc="2" rdt="2005-12-24 18:02:40" rdt_nc="1" .../>
<frame n="73" pts="00:00:02.920000" tc="00:00:01:11" rdt="2005-12-24 18:02:41" .../>
```

Der Sprung bei Frame 37 ist genau der Beginn der zweiten Aufnahme. Echte Camcorder setzen zusätzlich `rec_start`;
ffmpeg schreibt das beim Erzeugen nicht, das muss eine echte Datei zeigen.

Laufzeit: dvrescue liest die Datei einmal durch (DV ≈ 13 GB/Stunde), begrenzt durch die Platte, keine Dekodierung.
Gelesen werden muss das **Original** (die MP4 hat keine Subcodes mehr). Interlaced Originale bleiben seit dem Umbau
ohnehin erhalten.

## 2. Aufnahme-Segmente

Neue Entity `RecordingSegment`: `video`, `startSeconds`, `endSeconds` (Position im Video, aus PTS), `recordedAt`
(UTC), `cameraRecordedAt` (Kamerazeit wie gelesen, für Korrekturen), `source` (`dv` | `manual`).

- Grenze: `rec_start`, ein Sprung des Datums (`rdt_nc`) oder ein Wechsel von „Datum vorhanden“ zu „fehlt“.
- Kurze Störungen (einzelne Frames mit kaputtem Subcode, Bandfehler) nicht als Segment zählen: Mindestlänge
  z. B. 1 s, sonst zum Nachbarn.
- Zeitzone: Die Kamera speichert Ortszeit ohne Zone → wie beim Dateinamen als Europe/Berlin lesen und nach UTC umrechnen
  (bei Urlaubsaufnahmen im Ausland ist das ungenau, wie heute auch).
- `Video.takenAt` = Beginn des ersten Segments, `dateSource = dv` (neu).

## 3. Segmentgrenzen und Szenen

Ja, Segmentgrenzen sind sichere Schnitte:
- in Schritt 2 zusätzlich zur optischen Szenenerkennung als Szenengrenze einfügen, wo PySceneDetect keinen Schnitt
  gefunden hat (Start/Stopp ohne Bildwechsel kommt bei Camcordern oft vor);
- `EmptyScenesMerger` darf leere Szenen **nie** über eine Segmentgrenze hinweg zusammenlegen; dasselbe für die
  Kapitel (eine Szene/ein Kapitel gehört immer zu genau einem Tag);
- `Video::sceneCuts` bleibt die rohe optische Liste (für den Abgleich der aufbereiteten Fassung); die Segmente
  stehen getrennt.

## 4. Wer nutzt heute die Aufnahmezeit, und was ändert sich

| Stelle | heute | mit Segmenten |
|---|---|---|
| `MediaTimeline` (Zeitleiste) | ein Eintrag je Medium, sortiert nach `takenAt` | Video weiter am Beginn des ersten Segments; Ausbaustufe: Segmente als eigene Einträge an ihren Tagen (Sprung an die Stelle im Video) |
| Suche (`MediumIndexer`, `VideoIndexer`, `MediaSearch`) | `takenAt` des Mediums | zusätzlich Liste der Segment-Tage, damit „Weihnachten 2005“ die Kassette findet |
| Ereignisse (`EventMediaCollector`, `EventSuggester`) | Medium im Zeitraum | ein Video gehört zu jedem Ereignis, in dessen Zeitraum ein Segment fällt; Vorschläge je Segment |
| Pools (`Pools`, Regel „von–bis“) | `takenAt` | „irgendein Segment im Zeitraum“ (Freigabe des ganzen Videos; Freigabe nur von Szenen wäre ein eigener Schritt) |
| Gesichter: Referenzauswahl (3f, `EmbeddingIndex`) | Jahr des Mediums | Jahr des Segments, in dem das Gesicht liegt (`VideoFace.timestamp` → Segment). Ohne Segmente wie heute |
| `RecordingTimeCorrector`, `CameraClock` | verschiebt `takenAt` | verschiebt alle Segmente eines Videos um denselben Betrag (siehe 6) |
| Karte, Fotoserien | nur Fotos/Ort | unverändert |

Empfohlene Reihenfolge: (a) Auslesen und Segmente speichern, `takenAt` aus dem ersten Segment, Segmentgrenzen als
Szenengrenzen; (b) Referenzauswahl und Suche je Segment; (c) Ereignisse/Pools/Zeitleiste je Segment.

## 5. Priorität der Quellen

DV-Subcode → Dateiname → `creation_time` → Importzeit. Bei DV-Dateien ohne Subcodes sind Dateiname und
`creation_time` meist das Digitalisierungsdatum; dann als unsicher kennzeichnen (Hinweis an der Videoseite wie heute
bei unplausiblen Kamerazeiten).

## 6. Falsch gestellte Kamera-Uhr bei mehreren Segmenten

- Meist ging die Uhr über die ganze Kassette gleich falsch (nie gestellt, Sommerzeit vergessen): **ein** Versatz für
  alle Segmente. Die Korrektur an einem Segment („diese Aufnahme war am 24.12.2005 um 18 Uhr“) verschiebt alle
  Segmente des Videos um denselben Betrag; die Abstände bleiben. `CameraClockOffset` funktioniert weiter je Kamera und
  Zeitfenster, angewandt auf die Kamerazeit jedes Segments.
- Uhr mitten in der Kassette zurückgesetzt (leerer Akku → Werkszeit, z. B. 01.01.2000): dann springt das Datum, die
  folgenden Segmente sind unplausibel. Dafür: Korrektur ab einem Segment („ab hier“) statt für alle.
- `CameraClock::EARLIEST_PLAUSIBLE` ist 2000-01-01. MiniDV gibt es seit 1995, Digital8 seit 1999; für DV muss die
  Grenze früher liegen (siehe Rückfragen).

## 7. DV ohne Subcodes

Schon umkodiert (MPEG-2/MP4 aus der Capture-Software, HitPaw) oder Kassetten ohne gestellte Uhr: dvrescue findet kein
`rdt`. Dann ein Segment für das ganze Video mit Datum aus Dateiname/`creation_time` (als unsicher markiert), Szenen
nur optisch. Ausbaustufe: Segmente von Hand anlegen (Bereich im Video + Datum), z. B. auf der Videoseite an
Szenengrenzen. Hinweis an die Digitalisierung: als DV-AVI/`.dv` ohne Umkodierung übernehmen (WinDV, dvgrab,
ffmpeg `-c copy`), sonst sind die Subcodes weg.

## Quellen

- [DVRescue Documents (XML-Schema: rdt, rdt_nc, rec_start, rec_end)](https://mipops.github.io/dvrescue/sections/dvrescue_documents.html)
- [DVRescue: DV Analysis](https://mipops.github.io/dvrescue/sections/analysis.html)
- [DV Analyzer: Temporal metadata](https://mediaarea.net/DVAnalyzer/dv-metadata),
  [What does it analyze?](https://mediaarea.net/DVAnalyzer/what-does-it-analyze)
- [MediaArea Repositories](https://mediaarea.net/Repos)
