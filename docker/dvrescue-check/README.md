# Gegenprobe: dvrescue gegen read_dv_subcodes.py

Nur zum Prüfen von Hand, **nicht** Teil des App-Images (dort liest `video-analyzer/python/read_dv_subcodes.py`).
Vergleicht für eine DV-Datei das Aufnahmedatum an jedem Frame, den dvrescue meldet, und die Segmentgrenzen
(Aufnahmebeginn, Datumssprung, Datum taucht auf/fällt weg) mit `read_dv_subcodes.py` und listet Abweichungen.

```sh
docker build -t momentary-dvrescue-check:local docker/dvrescue-check
docker run --rm \
  -v "$PWD/video-analyzer/python:/reader:ro" \
  -v "$PWD/docker/dvrescue-check:/check:ro" \
  -v "$PWD/dv_example:/in:ro" \
  momentary-dvrescue-check:local python3 -I /check/compare.py /in/<datei>
```

Das Image holt dvrescue aus dem MediaArea-Repository (Debian 13). `read_dv_subcodes.py` liefert rohe Läufe; die
Regeln danach (Läufe unter 1 s, Plausibilität) wendet erst `RecordingSegmentBuilder` an.
