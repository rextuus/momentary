# face-service

Gesichtserkennung für Momentary mit [InsightFace](https://github.com/deepinsight/insightface) auf der CPU
(ONNX Runtime). Modellpaket `buffalo_l`: SCRFD-Detektion, ArcFace-Embeddings (512 Werte, L2-normalisiert),
Alter/Geschlecht. Gesamtbild und Einbindung in die Pipeline: `documentation/face_recognition.md`.

> **Lizenz der Modelle:** Der Code von InsightFace steht unter MIT, die vortrainierten Modelle (auch `buffalo_l`)
> sind aber **nur für nicht-kommerzielle Nutzung** freigegeben. Für das private Familienarchiv passt das; eine
> kommerzielle Nutzung bräuchte eine eigene Lizenz von InsightFace oder andere Modelle.

## Endpunkte

Nur im Docker-Netz erreichbar (`http://face-service:8000`), kein Port nach außen.

| Endpunkt | Zweck |
|---|---|
| `POST /detect` | Bild als multipart (Feld `image`) → Gesichter mit `box` (x1, y1, x2, y2 in Pixeln), `score`, `landmarks` (5 Punkte). Nur Detektion. |
| `POST /analyze` | wie `/detect`, zusätzlich `embedding`, `age`, `gender` (`M`/`F`) pro Gesicht |
| `GET /health` | 200, sobald die Modelle geladen sind (vorher 503) |

`/detect` und `/analyze` nehmen optional `?det_size=` (160–1280, Vielfaches von 32), `?min_score=` (0–1),
`?min_face_px=` und `?max_faces=`. Die beiden Filter greifen **vor** dem Embedding, damit Gruppenszenen nicht ausufern:
Gesichter, deren kürzere Box-Seite im Originalbild unter `min_face_px` liegt, und alle über die `max_faces` größten
hinaus (0 = alle) werden weder beschrieben noch zurückgegeben. Gesichter kommen nach Größe sortiert, das größte zuerst.
Momentary schickt beide Werte bei jeder Anfrage aus den Einstellungen mit.

Jede Antwort enthält `model` (Embeddings verschiedener Modelle sind nicht vergleichbar), `width`, `height`,
`filtered` (`too_small`, `over_limit`) und `timings_ms` (`decode`, `wait` = Warten auf eine laufende Anfrage,
`detect`, `embed`).

Es läuft immer nur eine Inferenz zur Zeit, weitere Anfragen warten. So bleibt die Last bei den eingestellten Threads,
egal wie viele Worker gleichzeitig fragen.

## Konfiguration (Umgebung)

| Variable | Standard | Bedeutung |
|---|---|---|
| `FACE_DET_SIZE` | 640 | Eingabegröße der Detektion; kleiner = schneller, findet aber weniger kleine Gesichter |
| `FACE_MIN_DET_SCORE` | 0.5 | Detektionen darunter werden verworfen |
| `FACE_INTRA_OP_THREADS` | 2 | Threads pro Modell (ONNX Runtime) |
| `FACE_INTER_OP_THREADS` | 1 | parallele Knoten im Modellgraphen |
| `FACE_MIN_FACE_PX` | 40 | Standard für `min_face_px`, wenn die Anfrage keinen Wert nennt (z. B. bench.py) |
| `FACE_MAX_FACES` | 10 | Standard für `max_faces` (0 = alle) |
| `FACE_ALLOW_SPINNING` | 0 | 1 = wartende Threads drehen aktiv weiter (geringfügig schneller, verbraucht aber CPU-Quote) |
| `FACE_MODEL_PACK` | buffalo_l | anderes InsightFace-Paket, z. B. `buffalo_s` (deutlich schneller, ungenauer). Ein Wechsel macht alle gespeicherten Embeddings unbrauchbar. |
| `FACE_MODELS_DIR` | /models | Ablage der Modelle (Volume `face_models`) |

## Ressourcen

In `compose.yaml`/`compose.prod.yaml`: höchstens 2 Kerne und 2 GB Speicher, `cpu_shares: 512` (bei Konkurrenz um die
CPU halbes Gewicht gegenüber anderen Containern, auch denen der anderen Projekte auf abyss). Speicherbedarf im
Betrieb rund 600–800 MB.

## Start und Messung

Beim ersten Start lädt der Dienst `buffalo_l` (ca. 280 MB) von GitHub ins Volume `face_models` (Healthcheck
`start_period` 5 min); danach startet er in wenigen Sekunden.

```bash
# Entwicklung
docker compose up -d --build face-service
docker compose logs -f face-service            # "Modell buffalo_l/w600k_r50 geladen …"

# Produktion (abyss), Stack-Ordner von Komodo
docker compose -p momentary -f compose.prod.yaml --env-file .env.prod.local up -d --build face-service

# Laufzeit messen (über HTTP, also mit Limits und Lock wie im Betrieb); Standard: Beispielbild, det_size 640 und 320
docker compose exec face-service python bench.py
# Mit eigenen Bildern, z. B. Frames eines Videos
docker compose cp ./frames face-service:/tmp/bench
docker compose exec face-service python bench.py /tmp/bench --runs 3 --det-size 640 --det-size 320
# Wirkung der Filter (0 = aus)
docker compose exec face-service python bench.py /tmp/bench --det-size 640 --min-face-px 0 --max-faces 0
docker compose exec face-service python bench.py /tmp/bench --det-size 640 --min-face-px 40 --max-faces 10
```
