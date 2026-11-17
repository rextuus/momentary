# Ton-Transkription

Der Ton eines Videos lässt sich lokal mit [faster-whisper](https://github.com/SYSTRAN/faster-whisper)
verschriftlichen. Das Gesprochene wird mit Zeitstempeln gespeichert, ist durchsuchbar und kann Tags für die
Szenen liefern.

Die Transkription ist der **letzte Schritt der Pipeline** (`TRANSCRIBING`, nach dem Jellyfin-Export). Ihre Dauer
steht wie bei allen Schritten in den Pipeline-Details. Weil sie auf schwacher Hardware etwa so lange dauert wie das
Video selbst, läuft der Schritt nicht im Pipeline-Worker, sondern im eigenen **transcription-worker** (Queue
`transcription`). Videos in diesem Schritt belegen keinen der Plätze der Import-Queue, neue Videos starten also
trotzdem.

Abschaltbar unter *Verwalten → Transkription → Jedes neue Video automatisch transkribieren*; der Schritt wird dann
übersprungen. Schlägt die Transkription fehl (z. B. keine Tonspur), ist das Video trotzdem fertig.

## Manuell starten (z. B. neu transkribieren)

- **Videoseite**: Karte „Transkript“ → *Ton transkribieren* bzw. *Neu transkribieren*.
- **Kommandozeile**: `bin/console app:video:transcribe 12 13` oder `--missing` für alle fertigen Videos ohne Transkript.

## Ablauf

1. `TranscriptionRequester` legt bzw. setzt das `VideoTranscript` auf *wartet* und schickt eine
   `TranscribeVideoMessage` in die eigene Queue `transcription`.
2. Der Container **transcription-worker** verarbeitet diese Queue: ein Job zur Zeit, mit `nice` niedriger
   priorisiert, damit die Video-Pipeline und andere Dienste flüssig bleiben.
3. `VideoTranscriber`:
   - löst den Ton mit ffmpeg als 16-kHz-Mono-WAV heraus (`AudioExtractor`),
   - ruft `video-analyzer/python/transcribe.py` auf (`WhisperRunner`); das Skript meldet seinen Fortschritt auf
     stderr, die Videoseite zeigt ihn live an,
   - verwirft typische Whisper-Halluzinationen wie „Untertitel im Auftrag des ZDF“ (`TranscriptSegmentFilter`),
   - speichert die Abschnitte (`TranscriptSegment`) und aktualisiert den Suchindex (Feld `transcript` mit
     `start`/`end`/`text`, damit Treffer an die richtige Stelle springen können),
   - leitet optional Tags je Szene ab: ein Gemini-Aufruf **nur mit Text** (Themen, erwähnte Personen, Orte,
     Anlässe), die Tags landen als KI-Tags an den Szenen.

Fehler (keine Tonspur, Modell nicht ladbar …) stehen im Transkript und auf der Videoseite. Die Queue hat bewusst
keine Wiederholungen: eine stundenlange Transkription soll nicht automatisch neu starten.

Wird ein Video neu analysiert, bleiben die Szenen-Tags aus dem Transkript nicht erhalten (die Szenen werden neu
erzeugt). In dem Fall einfach neu transkribieren.

## Wo man das Transkript sieht

- **Videoseite**, Karte „Transkript“: Text nach Szenen, Suche, Klick auf den Zeitstempel springt im Player dorthin.
- **Timeline** (`/video/{id}/timeline`): oben die Sprach-Spur (wo im Video gesprochen wird, Klick springt ins Video),
  in jedem Kapitel bzw. jeder Szene ein Abschnitt „Gesprochen“.
- **Jellyfin**: Nach der Transkription liegt neben dem exportierten Video eine Untertiteldatei
  `<Name>.<Sprache>.srt` (`JellyfinSubtitleExporter`, `SrtWriter`), anschließend wird der Jellyfin-Eintrag neu
  eingelesen. Im Player erscheint die Spur als „German – SUBRIP – Extern“. Lange Abschnitte werden auf Untertitel
  von höchstens ~7 s aufgeteilt.
- **Suche**: Feld `transcript` im Meilisearch-Index.

Für Transkripte, die vor dem Untertitel-Export entstanden sind:
`bin/console app:transcription:subtitles` schreibt die SRT-Dateien aller fertigen Transkripte (neu).

## Einstellungen

`/admin/settings/transcription` (Verwalten → Transkription):

| Einstellung | Standard | Hinweis |
|---|---|---|
| Automatisch in der Pipeline | an | letzter Schritt jeder Analyse |
| Modell | `large-v3-turbo` | deutlich besser bei Namen und Dialekt als `small`, kaum langsamer |
| CPU-Threads | 3 | auf dem ThinkCentre (4 Kerne) einen Kern frei lassen |
| Sprache | `de` | leer = je Video automatisch erkennen |
| Tags ableiten | an | ein Gemini-Text-Aufruf pro Video |

Die Modelle werden beim ersten Lauf nach `var/whisper-models` geladen (`WHISPER_MODELS_DIR`), etwa 0,5 GB
(`small`) bis 1,6 GB (`large-v3-turbo`).

## Geschwindigkeit messen

```bash
docker compose exec transcription-worker php bin/console app:transcription:benchmark <Video-ID>
# Optionen: --seconds=120 --model=small --threads=4
```

Gibt Rechenzeit, Faktor zur Echtzeit und eine Hochrechnung pro Stunde Video aus. Probelauf (Oktober 2026):

| Rechner | Modell | Faktor |
|---|---|---|
| i7-1265U, 4 Threads, nur AVX2 | large-v3-turbo | 0,53 × Echtzeit |
| i7-1265U, 4 Threads, nur AVX2 | small | 0,26 × Echtzeit |
| ThinkCentre M710q (i5-6500T), geschätzt | large-v3-turbo | 0,7–1,0 × Echtzeit |

## Technische Hinweise

- Das Skript liest die WAV-Datei selbst ein, statt sie faster-whisper zu übergeben: die über
  `scenedetect[av]` installierte PyAV-Version passt nicht zu der von faster-whisper erwarteten.
- Die Spracherkennung vorab (VAD) ist aktiv; Clips ohne Sprache ergeben ein leeres Transkript.
