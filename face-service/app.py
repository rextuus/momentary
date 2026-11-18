"""Momentary face-service: Gesichter finden (SCRFD) und beschreiben (ArcFace, Alter/Geschlecht) mit InsightFace.

Endpunkte (nur im Docker-Netz erreichbar):
  POST /detect   Bild (multipart, Feld "image") -> Gesichter mit Box, Score, Landmarks. Nur Detektion.
  POST /analyze  wie /detect, zusätzlich pro Gesicht ein L2-normalisiertes 512er-Embedding, Alter, Geschlecht.
  GET  /health   200, sobald die Modelle geladen sind.

Jede Antwort nennt das Erkennungsmodell ("model"): Embeddings verschiedener Modelle dürfen nicht verglichen werden.

Beide POST-Endpunkte nehmen optional ?det_size=<px> (Vielfaches von 32), ?min_score=<0..1>,
?min_face_px=<px> und ?max_faces=<n>. Die beiden letzten filtern vor dem Embedding: Gesichter, deren kürzere Box-Seite
(im Originalbild) kleiner als min_face_px ist, und alle über die max_faces größten hinaus werden nicht beschrieben und
nicht zurückgegeben; "filtered" nennt, wie viele es waren. Gesichter kommen nach Größe sortiert, das größte zuerst.
Es läuft immer nur eine Inferenz zur Zeit; weitere Anfragen warten (wait_ms in der Antwort).
"""

from __future__ import annotations

import glob
import logging
import os
import threading
import time
from contextlib import asynccontextmanager
from dataclasses import dataclass

import cv2
import numpy as np
import onnxruntime as ort
from fastapi import FastAPI, File, HTTPException, Query, UploadFile
from fastapi.responses import JSONResponse
from insightface.app.common import Face
from insightface.model_zoo import SCRFD, ArcFaceONNX, Attribute
from insightface.utils.storage import ensure_available

log = logging.getLogger("face-service")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")


def _env_int(name: str, default: int) -> int:
    return int(os.environ.get(name, default))


def _env_float(name: str, default: float) -> float:
    return float(os.environ.get(name, default))


MODELS_DIR = os.environ.get("FACE_MODELS_DIR", "/models")
MODEL_PACK = os.environ.get("FACE_MODEL_PACK", "buffalo_l")
DET_SIZE = _env_int("FACE_DET_SIZE", 640)
MIN_DET_SCORE = _env_float("FACE_MIN_DET_SCORE", 0.5)
INTRA_OP_THREADS = _env_int("FACE_INTRA_OP_THREADS", 2)
INTER_OP_THREADS = _env_int("FACE_INTER_OP_THREADS", 1)
# Wartende ONNX-Threads drehen sonst aktiv weiter und verbrauchen CPU-Quote, die den anderen Diensten fehlt
ALLOW_SPINNING = os.environ.get("FACE_ALLOW_SPINNING", "0") == "1"
MAX_UPLOAD_BYTES = _env_int("FACE_MAX_UPLOAD_BYTES", 20 * 1024 * 1024)
# Standardwerte der Filter vor dem Embedding; Momentary schickt sie pro Anfrage aus den Einstellungen mit
MIN_FACE_PX = _env_int("FACE_MIN_FACE_PX", 40)
MAX_FACES = _env_int("FACE_MAX_FACES", 10)


def _model_file(pack_dir: str, pattern: str) -> str:
    """Modelldatei im Paket; die Namen unterscheiden sich je Paket (buffalo_l: det_10g, w600k_r50; buffalo_s: det_500m, w600k_mbf).
    Die Landmark-Modelle (1k3d68, 2d106det) brauchen wir nicht."""
    matches = sorted(glob.glob(os.path.join(pack_dir, pattern)))
    if not matches:
        raise RuntimeError(f"Kein Modell {pattern} in {pack_dir}")
    return matches[0]


@dataclass
class Models:
    detector: SCRFD
    recognizer: ArcFaceONNX
    genderage: Attribute
    recognition_model: str


models: Models | None = None
# Eine Inferenz zur Zeit: So bleibt es bei INTRA_OP_THREADS Kernen, auch wenn Fotos und Videos gleichzeitig fragen
inference_lock = threading.Lock()


def _session(path: str) -> ort.InferenceSession:
    options = ort.SessionOptions()
    options.intra_op_num_threads = INTRA_OP_THREADS
    options.inter_op_num_threads = INTER_OP_THREADS
    options.execution_mode = ort.ExecutionMode.ORT_SEQUENTIAL
    options.add_session_config_entry("session.intra_op.allow_spinning", "1" if ALLOW_SPINNING else "0")
    return ort.InferenceSession(path, sess_options=options, providers=["CPUExecutionProvider"])


def load_models() -> Models:
    # Lädt das Paket beim ersten Start herunter (ca. 280 MB) und entpackt es nach <MODELS_DIR>/models/<pack>
    pack_dir = ensure_available("models", MODEL_PACK, root=MODELS_DIR)
    # Das ZIP bleibt sonst neben dem entpackten Paket im Volume liegen
    zip_path = pack_dir + ".zip"
    if os.path.exists(zip_path):
        os.remove(zip_path)

    detection = _model_file(pack_dir, "det_*.onnx")
    recognition = _model_file(pack_dir, "w600k_*.onnx")
    attributes = _model_file(pack_dir, "genderage.onnx")

    detector = SCRFD(model_file=detection, session=_session(detection))
    detector.prepare(-1, input_size=(DET_SIZE, DET_SIZE), det_thresh=MIN_DET_SCORE)
    recognizer = ArcFaceONNX(model_file=recognition, session=_session(recognition))
    recognizer.prepare(-1)
    genderage = Attribute(model_file=attributes, session=_session(attributes))
    genderage.prepare(-1)

    # Embeddings verschiedener Erkennungsmodelle sind nicht vergleichbar; der Name wird mitgeliefert
    return Models(detector, recognizer, genderage, f"{MODEL_PACK}/{os.path.basename(recognition)[:-5]}")


@asynccontextmanager
async def lifespan(_: FastAPI):
    global models
    started = time.perf_counter()
    models = load_models()
    log.info(
        "Modell %s geladen in %.1f s (det_size %d, min_score %.2f, Threads %d/%d, Spinning %s)",
        models.recognition_model, time.perf_counter() - started, DET_SIZE, MIN_DET_SCORE,
        INTRA_OP_THREADS, INTER_OP_THREADS, "an" if ALLOW_SPINNING else "aus",
    )
    yield


app = FastAPI(title="momentary face-service", lifespan=lifespan, docs_url=None, redoc_url=None)


@app.get("/health")
def health() -> JSONResponse:
    if models is None:
        return JSONResponse({"status": "loading"}, status_code=503)
    return JSONResponse({
        "status": "ok",
        "model": models.recognition_model,
        "det_size": DET_SIZE,
        "min_det_score": MIN_DET_SCORE,
        "intra_op_threads": INTRA_OP_THREADS,
        "inter_op_threads": INTER_OP_THREADS,
        "allow_spinning": ALLOW_SPINNING,
        "min_face_px": MIN_FACE_PX,
        "max_faces": MAX_FACES,
    })


@app.post("/detect")
def detect(
    image: UploadFile = File(...),
    det_size: int | None = Query(None, ge=160, le=1280, multiple_of=32),
    min_score: float | None = Query(None, ge=0.0, le=1.0),
    min_face_px: int | None = Query(None, ge=0, le=4096),
    max_faces: int | None = Query(None, ge=0, le=1000),
) -> dict:
    return _run(image, det_size, min_score, min_face_px, max_faces, with_embeddings=False)


@app.post("/analyze")
def analyze(
    image: UploadFile = File(...),
    det_size: int | None = Query(None, ge=160, le=1280, multiple_of=32),
    min_score: float | None = Query(None, ge=0.0, le=1.0),
    min_face_px: int | None = Query(None, ge=0, le=4096),
    max_faces: int | None = Query(None, ge=0, le=1000),
) -> dict:
    return _run(image, det_size, min_score, min_face_px, max_faces, with_embeddings=True)


def _decode(upload: UploadFile) -> np.ndarray:
    data = upload.file.read(MAX_UPLOAD_BYTES + 1)
    if len(data) > MAX_UPLOAD_BYTES:
        raise HTTPException(413, "Bild zu groß")
    img = cv2.imdecode(np.frombuffer(data, dtype=np.uint8), cv2.IMREAD_COLOR)
    if img is None:
        raise HTTPException(422, "Bild konnte nicht gelesen werden")
    return img  # BGR, wie InsightFace es erwartet


def select_faces(boxes: np.ndarray, min_face_px: int, max_faces: int) -> tuple[list[int], int, int]:
    """Indizes der zu beschreibenden Gesichter, größte zuerst, sowie Anzahl zu kleiner und über max_faces hinaus.

    boxes: (n, 4) mit x1, y1, x2, y2 in Pixeln des Originalbilds; max_faces 0 = unbegrenzt.
    """
    if boxes.shape[0] == 0:
        return [], 0, 0
    widths = boxes[:, 2] - boxes[:, 0]
    heights = boxes[:, 3] - boxes[:, 1]
    big_enough = [i for i in range(boxes.shape[0]) if min(widths[i], heights[i]) >= min_face_px]
    big_enough.sort(key=lambda i: float(widths[i] * heights[i]), reverse=True)
    too_small = boxes.shape[0] - len(big_enough)
    if max_faces > 0 and len(big_enough) > max_faces:
        return big_enough[:max_faces], too_small, len(big_enough) - max_faces
    return big_enough, too_small, 0


def _run(
    upload: UploadFile,
    det_size: int | None,
    min_score: float | None,
    min_face_px: int | None,
    max_faces: int | None,
    with_embeddings: bool,
) -> dict:
    if models is None:
        raise HTTPException(503, "Modelle werden noch geladen")

    t0 = time.perf_counter()
    img = _decode(upload)
    t_decoded = time.perf_counter()

    size = det_size or DET_SIZE
    threshold = MIN_DET_SCORE if min_score is None else min_score

    with inference_lock:
        t_locked = time.perf_counter()
        # det_thresh gilt pro Aufruf; unter dem Lock ist das gefahrlos
        models.detector.det_thresh = threshold
        bboxes, kpss = models.detector.detect(img, input_size=(size, size), max_num=0, metric="default")
        t_detected = time.perf_counter()

        selected, too_small, over_limit = select_faces(
            bboxes[:, 0:4],
            MIN_FACE_PX if min_face_px is None else min_face_px,
            MAX_FACES if max_faces is None else max_faces,
        )
        faces = []
        for i in selected:
            x1, y1, x2, y2, score = (float(v) for v in bboxes[i])
            kps = kpss[i] if kpss is not None else None
            entry = {
                "box": [round(x1, 1), round(y1, 1), round(x2, 1), round(y2, 1)],
                "score": round(score, 4),
                "landmarks": [[round(float(x), 1), round(float(y), 1)] for x, y in kps] if kps is not None else [],
            }
            if with_embeddings and kps is not None:
                face = Face(bbox=bboxes[i, 0:4], kps=kps, det_score=score)
                embedding = models.recognizer.get(img, face)
                norm = float(np.linalg.norm(embedding))
                gender, age = models.genderage.get(img, face)
                entry["embedding"] = [round(float(v), 6) for v in (embedding / norm if norm > 0 else embedding)]
                entry["age"] = int(age)
                entry["gender"] = "M" if int(gender) == 1 else "F"
            faces.append(entry)
        t_done = time.perf_counter()

    height, width = img.shape[:2]
    return {
        "model": models.recognition_model,
        "width": width,
        "height": height,
        "det_size": size,
        "faces": faces,
        "filtered": {"too_small": too_small, "over_limit": over_limit},
        "timings_ms": {
            "decode": round((t_decoded - t0) * 1000, 1),
            "wait": round((t_locked - t_decoded) * 1000, 1),
            "detect": round((t_detected - t_locked) * 1000, 1),
            "embed": round((t_done - t_detected) * 1000, 1),
        },
    }
