# Momentary face-service: Gesichtserkennung mit InsightFace (buffalo_l) auf der CPU.
# Die Modelle sind nicht im Image, sie landen beim ersten Start im Volume unter /models.

# insightface gibt es nur als Quellpaket mit Cython-Teil: hier mit Compiler zu Wheels bauen
FROM python:3.12.8-slim-bookworm AS build
RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential \
    && rm -rf /var/lib/apt/lists/*
COPY requirements.txt /tmp/requirements.txt
RUN pip wheel --no-cache-dir --wheel-dir /wheels -r /tmp/requirements.txt

FROM python:3.12.8-slim-bookworm
# libgl/libglib braucht opencv auch in der headless-Variante nicht, libgomp aber onnxruntime
RUN apt-get update \
    && apt-get install -y --no-install-recommends libgomp1 \
    && rm -rf /var/lib/apt/lists/*
COPY --from=build /wheels /wheels
RUN pip install --no-cache-dir --no-index --find-links /wheels /wheels/* && rm -rf /wheels

# Ohne root; /models gehört dem Dienst, damit ein neues Volume beim ersten Einhängen dessen Rechte übernimmt
RUN useradd --uid 10001 --no-create-home --home-dir /app face \
    && mkdir -p /models \
    && chown face:face /models
WORKDIR /app
COPY --chown=face:face app.py bench.py ./
USER face

# matplotlib (Abhängigkeit von insightface) will sonst ein Cache-Verzeichnis im nicht beschreibbaren /app anlegen
ENV FACE_MODELS_DIR=/models \
    MPLCONFIGDIR=/tmp/matplotlib \
    PYTHONUNBUFFERED=1
EXPOSE 8000
# Ein Prozess: Das Modell liegt nur einmal im Speicher, Anfragen laufen ohnehin nacheinander (Lock in app.py)
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1", "--no-access-log"]
