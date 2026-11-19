# Aufnahmedatum aus DV-Subcodes (MiniDV, Digital8)

Stand 19.11.2026: **Stufe 1 gebaut** (Auslesen, Segmente, `takenAt`, Szenengrenzen, Plausibilität). Noch nicht an
einer echten Kassette geprüft (Aufnahmewechsel, PAL, AVI aus der Capture-Software). Offene Punkte: `TODO.md`,
`RUECKFRAGEN.md`. Die Abschnitte 1–7 unten waren der Vorschlag; was davon gebaut ist, steht unter „Umsetzung“.

## Umsetzung (Stufe 1)

| Teil | Wo |
|---|---|
| Auslesen | `video-analyzer/python/read_dv_subcodes.py` (eigener Leser statt dvrescue, keine Fremdpakete): liest nur Subcode- und VAUX-Blöcke (5 von 150 je DIF-Sequenz), Datum/Uhrzeit (Packs 0x62/0x63) aus beiden Bereichen, je Frame der häufigste gültige Wert; REC ST (0x61) als Aufnahmebeginn. Gibt Läufe aus: neuer Lauf bei Aufnahmebeginn, Zeitsprung (rückwärts oder > 2 s) oder Wechsel Datum ↔ kein Datum. AVI/MOV reicht ffmpeg mit `-c copy -f rawvideo` byte-genau als rohes DV durch. |
| Aufruf | `DvSubcodeReader` (nur wenn der Video-Codec `dvvideo` ist, `RecordingSegments`) |
| Segmente | `RecordingSegmentBuilder`: ungültige Zeiten (vor 1995, > 1 Tag Zukunft) = kein Datum; Läufe < 1 s gehen im vorigen auf; nahtlos weiterlaufende Uhr ohne Aufnahmebeginn setzt das Segment fort; Werksdatum (`FactoryDate`: 1. Januar, erste Stunde) → `suspicious`, Datum wird nicht verwendet. Entity `RecordingSegment` (Bereich, `recordedAt`/`cameraRecordedAt` in UTC aus Ortszeit Europe/Berlin, `source`, `suspicious`, `recurringDate`). |
| Wiederkehrende Daten | `RecurringRecordingDates`: derselbe Kameratag auf ≥ 2 Kassetten jeweils vor 3 Uhr oder auf ≥ 3 Kassetten → `recurringDate` an allen beteiligten Segmenten. **Nur ein Hinweis** („prüfen“ auf der Videoseite); das Datum gilt weiter, auch für `takenAt`. Wird nie automatisch zurückgenommen. |
| Aufnahmezeit | `VideoSourceMetadata` (Schritt 1): erstes Segment mit verlässlichem Datum → `takenAt`, `dateSource = dv`; ersetzt Dateiname/Metadaten, nie eine Korrektur von Hand. Ohne verlässliches Datum die bisherigen Quellen; Hinweis „Zeit stammt vermutlich vom Digitalisieren“ (`RecordingSegments::warnings`, über `CameraClock::warnings`). |
| Szenen, Kapitel | `VideoSceneService::storeScenes` teilt Szenen an Segmentgrenzen (ein optischer Schnitt bis 0,5 s daneben gilt als dieselbe Stelle); `Video::sceneCuts` bleibt optisch. `EmptyScenesMerger` legt nur innerhalb einer Aufnahme zusammen (`RecordingGroups`). |
| Anzeige | Videoseite → Datei-Info → „Aufnahmen laut DV-Kassette“ |
| Grenze | `CameraClock::EARLIEST_PLAUSIBLE` = 1995-01-01; Werksdatum-Hinweis auch für Fotos |

Gelesen wird bei jedem Verarbeiten, solange die Quelldatei DV ist; ist sie umgewandelt, bleiben die Segmente.
Segmente mit `source = manual` überschreibt keine Analyse (eine Oberfläche dafür gibt es noch nicht). Ist die Datei
nicht lesbar, läuft die Verarbeitung weiter wie ohne Subcodes (Warnung im Log).

Tests: `RecordingSegmentBuilderTest`, `RecordingBoundariesTest`, `DvRecordingDateTest` (echtes ffmpeg, nur
künstliche Kassetten: ffmpeg schreibt `creation_time` als Aufnahmedatum; fehlende Subcodes entstehen durch Leeren der
Subcode-/VAUX-Blöcke), `EmptyScenesMergerRecordingTest`, `ChapterRecordingTest`, `VideoMaterialInfoTest`. Die
Beispieldateien aus `dv_example/` sind nicht im Repository: ihre Herkunft und Lizenz ließen sich nicht belegen
(nicht in dvrescue bzw. dessen `dvsampler`; die BSD-3-Lizenz von dvrescue deckt nur den Code).

Gegenprobe: `docker/dvrescue-check/` vergleicht eine Datei mit dvrescue (nur lokal, nicht im App-Image). Bei den
künstlichen Kassetten (roh und AVI) und den zwei Beispieldateien stimmen Datum und Grenzen überein.

Kapitel: Ein Kapitel, das Gemini über eine Aufnahmegrenze vorschlägt, wird dort geteilt (beide Teile behalten Titel
und Beschreibung; `ChapterGenerator`, Test `ChapterRecordingTest`). Die API zeigt Segmente nicht (bei Videos fehlt
`takenAt` in der API ohnehin noch). Noch offen für Stufe 1: Prüfung an einer echten Kassette.

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

### Befund an den Beispieldateien (`dv_example/`, 19.11.2026)

Zwei rohe `.dv`-Dateien (NTSC 720×480, je 3 Frames), Herkunft unbekannt (nicht im dvrescue-Repository gefunden). Ausgelesen mit
einem eigenen Python-Skript (DIF-Blöcke, Packs 0x13/0x61/0x62/0x63 aus Subcode und VAUX); dvrescue selbst ließ sich
in dieser Sitzung nicht installieren.

| Datei | Befund |
|---|---|
| `BAVC2000005.dv.no_pack…` | **keine Packs** in keinem Frame: kein Datum, kein Timecode. Genau der Fall „DV ohne gültige Subcodes“ (7). |
| `snowball.dv.no_pack_aud…` | Aufnahme **2002-12-29 15:15:05** in allen Frames, Timecode 00:11:05;12 bis ;14. Frame 0 hat das Datum nur im VAUX, nicht im Subcode; Frames 1–2 in beiden. Bildfehler (ffmpeg verbirgt Blöcke). |

Folgerungen:
- Datum aus **Subcode oder VAUX** nehmen, je Frame das häufigste gültige; ein Bereich allein reicht nicht.
- Jahr ist zweistellig (BCD): `00–49` → 20xx, `50–99` → 19xx.
- ffprobe meldet bei beiden nur „timecode is invalid“; ohne eigenes Auslesen bleibt das Datum unsichtbar.
- Nicht gezeigt haben die Dateien: Wechsel zwischen Aufnahmen (`rec_start`, Datumssprung), PAL, DV in AVI.
  Dafür braucht es weiter eine echte Kassette.

**Alternative zu dvrescue:** Das Auslesen ist mit wenig Code selbst machbar (Python, ein Durchlauf über die Datei,
keine Dekodierung). DV in AVI/MOV vorher mit `ffmpeg -i <datei> -c copy -f dv -` als rohes DV durchreichen. Dann
braucht das Image kein Fremdpaket; dvrescue bliebe nur als Prüfwerkzeug.

## 2. Aufnahme-Segmente

Neue Entity `RecordingSegment`: `video`, `startSeconds`, `endSeconds` (Position im Video, aus PTS), `recordedAt`
(UTC, `null` = kein gültiges Datum, siehe 7), `cameraRecordedAt` (Kamerazeit wie gelesen, für Korrekturen),
`source` (`dv` | `manual`), `suspicious` (Werksdatum, siehe 6), `recurringDate` (Hinweis, siehe 6), `precision` (Tag | Monat | Jahr, für von Hand
nachgetragene Daten).

- Grenze: `rec_start`, ein Sprung des Datums (`rdt_nc`) oder ein Wechsel von „Datum vorhanden“ zu „fehlt“.
- Kurze Störungen (einzelne Frames mit kaputtem Subcode, Bandfehler) nicht als Segment zählen: Mindestlänge
  z. B. 1 s, sonst zum Nachbarn.
- Zeitzone: Die Kamera speichert Ortszeit ohne Zone → wie beim Dateinamen als Europe/Berlin lesen und nach UTC umrechnen
  (bei Urlaubsaufnahmen im Ausland ist das ungenau, wie heute auch).
- `Video.takenAt` = Beginn des ersten Segments mit gültigem Datum (kein Werksdatum), `dateSource = dv` (neu);
  sonst Rückfall wie in 7.

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
- Uhr mitten in der Kassette zurückgesetzt (leerer Pufferakku → Werkszeit): dann springt das Datum, die folgenden
  Segmente sind unplausibel. Dafür: Korrektur ab einem Segment („ab hier“) statt für alle.

### Plausibilität (entschieden)

- **Früheste plausible Kamerazeit: 1995-01-01** (MiniDV ab 1995, Digital8 ab 1999) statt bisher 2000-01-01. Davor:
  Uhr nicht gestellt, kein Datum.
- **Verworfen** (zählt nicht für `takenAt`, Ereignisse, Pools) werden nur: vor 1995, mehr als einen Tag in der
  Zukunft und das **Werksdatum** nach leerem Pufferakku, also Beginn am **1. Januar in der ersten Stunde**
  (00:00–00:59; die Uhr läuft nach dem Zurücksetzen weiter, deshalb nicht nur genau 00:00:00). Das Werksdatum bleibt
  gespeichert (`cameraRecordedAt`, `suspicious = true`), bis es bestätigt oder korrigiert ist („ab hier“ oder von Hand).
- **Nur Hinweis zur Prüfung** (Entscheidung 19.11.2026): dasselbe Kameradatum **wiederkehrend** über Kassetten, d. h.
  Segmente aus mindestens zwei Kassetten beginnen am selben Kalendertag vor 3 Uhr, oder derselbe Tag kommt auf
  mindestens drei Kassetten vor. Bei Hochzeit, Taufe, Weihnachten oder Urlaub ist das normal; das Datum gilt weiter
  (`recurringDate = true`, Hinweis „Bitte prüfen“ auf der Videoseite).
- Die neue Grenze gilt erst zusammen mit der Regel für Standarddaten. Heute gilt `CameraClock::EARLIEST_PLAUSIBLE`
  auch für Fotos; allein auf 1995 gesetzt, bekäme eine auf 01.01.2000 zurückgesprungene Uhr keinen Hinweis mehr.
  Deshalb wird beides gemeinsam umgestellt, die Standarddatum-Regel auch für Fotos (`CameraClock::warnings`).

## 7. DV ohne gültige Subcodes (häufiger Fall)

Digital8-Camcorder spielen auch **analoge Hi8-/Video8-Kassetten** ab und geben sie als DV aus. Solche Dateien sind
echtes DV, haben aber kein Aufnahmedatum: `rdt` fehlt, ist leer (`0xFF`-Füllwerte) oder ungültig (Monat 0,
Jahr 2165 …), und es gibt keine `rec_start`-Kennzeichen. Dazu kommen schon umkodierte Dateien (MPEG-2/MP4 aus der
Capture-Software, HitPaw) und Kassetten mit nie gestellter Uhr. Dieser Fall ist kein Fehler, sondern Alltag, und muss
ohne Eingriff durchlaufen:

1. **Nur lesen, wo es Sinn hat.** dvrescue nur für DV-Container (Video-Codec `dvvideo`); alles andere überspringt den
   Schritt still.
2. **Ungültig = fehlt.** Jeder `rdt` wird geprüft: parsebar, nicht vor 1995-01-01, nicht mehr als einen Tag in der
   Zukunft. Ungültige Werte zählen wie fehlende. Einzelne gültige Frames in einem sonst datumslosen Bereich
   (Bandfehler, Rauschen beim analogen Abspielen) zählen erst ab der Mindestlänge (siehe 2) als Segment.
3. **Segment ohne Datum.** Hat ein Bereich kein gültiges Datum, bekommt er ein Segment mit `recordedAt = null`;
   eine Datei ganz ohne gültige Subcodes besteht aus einem einzigen solchen Segment über das ganze Video. Szenen kommen
   dann nur aus der optischen Erkennung. Gemischte Dateien (ein Teil mit Datum, ein Teil ohne) werden an der Grenze
   geteilt (siehe 2: Wechsel „Datum vorhanden“ ↔ „fehlt“).
4. **Rückfall für `takenAt`.** `Video.takenAt` kommt aus dem ersten Segment mit gültigem Datum (kein Werksdatum).
   Gibt es keins, gelten die bisherigen Quellen wie heute (Dateiname → `creation_time` → Importzeit). Weil das bei
   Digitalisierungen meist das Datum des Überspielens ist, zeigt die Videoseite einen Hinweis „Datum vermutlich vom
   Digitalisieren“; `dateSource` bleibt die tatsächliche Quelle.
5. **Von Hand nachtragen.** Segmente ohne Datum lassen sich auf der Videoseite datieren (`source = manual`), auch nur
   auf Jahr oder Monat genau, wenn mehr nicht bekannt ist (Feld für die Genauigkeit). Ausbaustufe: ein datumsloses
   Segment an Szenengrenzen teilen, wenn eine analoge Kassette mehrere Anlässe enthält. Ein von Hand gesetztes Datum
   überschreibt die nächste Analyse nie.
6. **Fehler von dvrescue** (Absturz, kaputte Datei, Zeitlimit) behandeln wie „keine Subcodes“: Hinweis im Schritt-Log,
   die Verarbeitung läuft weiter.

Tests: DV-AVI ohne Subcodes (analog überspielt), mit Füllwerten, mit ungültigem Datum, gemischt (Teil mit Datum, Teil
ohne), Standarddatum 01.01. 00:xx, dvrescue-Fehler, Nicht-DV-Datei.

Hinweis an die Digitalisierung: als DV-AVI/`.dv` ohne Umkodierung übernehmen (WinDV, dvgrab, ffmpeg `-c copy`), sonst
sind die Subcodes auch bei echten DV-Aufnahmen weg.

## Quellen

- [DVRescue Documents (XML-Schema: rdt, rdt_nc, rec_start, rec_end)](https://mipops.github.io/dvrescue/sections/dvrescue_documents.html)
- [DVRescue: DV Analysis](https://mipops.github.io/dvrescue/sections/analysis.html)
- [DV Analyzer: Temporal metadata](https://mediaarea.net/DVAnalyzer/dv-metadata),
  [What does it analyze?](https://mediaarea.net/DVAnalyzer/what-does-it-analyze)
- [MediaArea Repositories](https://mediaarea.net/Repos)
