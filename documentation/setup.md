# Setup & Configuration

To run Momentary, you need both PHP and Python environments configured.

## Prerequisites

-   **PHP 8.2+** with Composer.
-   **Python 3.10+** with `pip` and `venv`.
-   **FFmpeg**: Required for video processing and frame extraction.
-   **AWS Account**: With Rekognition access.

## Environment Variables (`.env.local`)

You must configure the following AWS credentials:

```env
AWS_ACCESS_KEY=your_access_key
AWS_SECRET_KEY=your_secret_key
AWS_REGION=eu-central-1
PYTHON_BINARY=/path/to/your/venv/bin/python3

# Jellyfin Integration (Empfohlen)
JELLYFIN_HOST=http://localhost:8096
JELLYFIN_API_KEY=dein_api_key
```

## Installation Steps

1.  **PHP Dependencies**:
    ```bash
    composer install
    ```

2.  **Database Migration**:
    ```bash
    php bin/console doctrine:migrations:migrate
    ```

3.  **Python Setup**:
    Navigate to `video-analyzer/python/`, create a virtual environment and install requirements:
    ```bash
    cd video-analyzer/python
    python3 -m venv venv
    source venv/bin/activate
    pip install -r requirements.txt  # Ensure requirements.txt exists or install: opencv-python scenedetect yt-dlp
    ```

4.  **AWS Rekognition Collection**:
    Create the collection used for face matching (default: `family-archive-collection`):
    ```bash
    php bin/console app:rekognition:setup
    ```
    See [Console Commands](console_commands.md) for more details.

5.  **Running the Worker**:
    Since video processing is asynchronous, you need to run the messenger worker. 
    
    If you are running the application outside of Docker (locally), use:
    ```bash
    php bin/console messenger:consume async -vv
    ```

    If you want to run it inside the Docker container (Recommended for video processing):
    ```bash
    docker exec -it momentary-app-1 php bin/console messenger:consume async -vv
    ```

    **Wichtig bei Änderungen am Dockerfile**: Falls du das Dockerfile änderst (z.B. um neue Tools wie `ffmpeg` zu installieren), musst du die Container neu bauen:
    ```bash
    docker compose build app
    docker compose up -d
    ```

    **Hinweis zur Video-Optimierung**: Die Optimierung für Jellyfin erfordert `python3` und `ffmpeg`. 
    - Im Docker-Container sind diese nun vorinstalliert (nach einem `docker compose build`).
    - Bei lokalem Betrieb müssen diese auf dem Host installiert sein und die `PYTHON_BINARY` in der `.env.local` muss auf den korrekten Pfad zeigen.

    **Wichtig bei lokalem Betrieb**: Die Anwendung ist so konfiguriert, dass sie Pfade zwischen Docker (`/var/www/html/`) und lokalen Pfaden automatisch auflöst. Zudem gibt es einen Fallback-Mechanismus für die `PYTHON_BINARY`: Falls der konfigurierte Pfad (z.B. ein lokaler venv-Pfad) in der aktuellen Umgebung (Docker) nicht existiert, wird automatisch der systemweite `python3`-Befehl genutzt. Dies stellt sicher, dass die Pipeline sowohl lokal als auch im Container ohne manuelle Anpassung der `.env.local` läuft.

    **Python-Abhängigkeiten im Container**:
    Falls Fehler wie `ModuleNotFoundError` auftreten, stelle sicher, dass das Docker-Image aktuell ist:
    ```bash
    docker compose build app
    docker compose up -d
    ```

    **Berechtigungen für Jellyfin-Export**: Falls du den Worker lokal ausführst, musst du dem Upload-Verzeichnis Schreibrechte geben, da Docker dieses Verzeichnis oft als `root` anlegt:
    ```bash
    sudo chmod -R 777 docker/jellyfin/uploads/
    ```

    **Note**: If you are running locally, ensure your `DATABASE_URL` in `.env.local` points to `127.0.0.1:9906`.
