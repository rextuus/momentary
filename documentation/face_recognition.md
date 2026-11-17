# Gesichtserkennung

## Ablauf

1. **Pro Frame (Analyse und Refinement):** `AmazonRekognitionService` nimmt alle Gesichter des Bildes per
   `indexFaces` in die Collection auf und gleicht danach jedes einzeln per `searchFaces` ab.
   (`searchFacesByImage` sucht nur mit dem größten Gesicht im Bild und taugt daher nicht für Gruppenszenen.)
   Zu kleine Gesichter werden verworfen und wieder aus der Collection gelöscht.
   Kosten: 1 Aufruf pro Frame + 1 pro Gesicht.
2. **Sofort-Zuordnung (`FaceMatcher`):** Alle Kandidaten werden pro Person zusammengefasst. Zugeordnet wird ab
   „Sofort zuordnen ab“. Ausgeschlossen sind Personen, die im selben Frame schon vorkommen. Ein abweichendes
   Geschlecht schließt einen Treffer unter 95 % aus. Zusammengeführte Personen leiten auf ihr Ziel weiter.
   Jedes Gesicht speichert seine Rekognition-FaceId (`faceLabel`) und alle Kandidaten (`matchCandidates`).
3. **Konsolidierung pro Video (Pipeline-Schritt „Gesichter im Video abgleichen“, `VideoFaceConsolidator`):**
   Alle Gesichter des Videos werden gruppiert: mit niedrigerer Schwelle und optional per Tracking
   (gleiche Bildposition, kurz nacheinander, gleiche Szene). Zwei Gesichter desselben Frames kommen nie in eine
   Gruppe, und zwei bestätigte Personen werden nie verschmolzen. Jede Gruppe wird genau einer Person zugeordnet:
   bestätigte Person > Person, die schon in anderen Videos erkannt wurde > häufigste Person der Gruppe.
   Leer gewordene, automatisch angelegte Personen werden gelöscht.

Als „bestätigt“ gilt jede Person mit einem anderen Status als `new` sowie jedes verifizierte Gesicht.

## Einstellungen

Unter **Admin → Gesichtserkennung** (`/admin/settings/face-recognition`), gespeichert in `face_recognition_settings`.
Änderungen gelten sofort, auch für einen laufenden Worker.

Faustregeln bei schlechten Aufnahmen:
- Gleiche Personen werden nicht zusammengelegt → „Innerhalb eines Videos gruppieren ab“ senken, Tracking anlassen.
- Verschiedene Personen werden zusammengelegt → „Sofort zuordnen ab“ erhöhen (wirkt videoübergreifend),
  „Mindestgröße“ erhöhen (Hintergrundgesichter).
- Viele Gesichter fehlen ganz → Qualitätsfilter auf `LOW` oder `NONE`.

Bereits analysierte Videos ohne neue AWS-Kosten neu gruppieren:

```bash
bin/console app:faces:consolidate <videoId>
bin/console app:faces:consolidate --all
```

Videos, die vor dieser Umstellung analysiert wurden, haben keine gespeicherten Kandidaten und müssen dafür einmal
neu analysiert werden.
