# Console Commands

Momentary provides several console commands for managing the video processing pipeline and AWS integration.

## General Commands

### `app:rekognition:setup`
Initializes the Amazon Rekognition Collection used for face matching.

**Usage:**
```bash
php bin/console app:rekognition:setup
```
-   Checks if the collection (default: `family-archive-collection`) exists.
-   Creates it if it is missing.
-   **Note:** Requires valid AWS credentials in `.env.local`.

---

## Video Management

### `app:video:admin`
An interactive command to manually trigger specific steps of the video processing pipeline.

**Usage:**
```bash
php bin/console app:video:admin
```
1.  Select a video from the database.
2.  Choose a processing step:
    -   **Download**: Starts the video download process.
    -   **Scenes**: Triggers scene detection (requires the video to be downloaded locally).
    -   **Split**: Triggers frame extraction and face analysis (requires the video to be downloaded locally).

---

## Testing & Analysis

### `app:jellyfin:test-export`
Exportiert ein Video zu Jellyfin zum Testen der API-Anbindung.

**Usage:**
```bash
# Lokal
php bin/console app:jellyfin:test-export <videoId>

# Im Docker-Container
docker exec momentary-app-1 php bin/console app:jellyfin:test-export <videoId>
```
-   `videoId`: Die ID des zu exportierenden Videos aus der Datenbank.
-   Kopiert die lokale Videodatei in das Jellyfin-Upload-Verzeichnis und triggert einen Library-Scan.

### `app:video:analyze-scenes`
Tests the local scene detection for a specific video and displays the results in a table.

**Usage:**
```bash
php bin/console app:video:analyze-scenes <videoId> [options]
```

**Options:**
-   `--threshold` | `-t`: Adjusts detection sensitivity (default: 27.0). Lower values detect more scenes.
-   `--detector` | `-d`: Choose between `content` (default) and `adaptive`. `adaptive` is better for analog material.
-   `--convert` | `-c`: **Highly recommended for MPG files.** Converts the video to MP4 before analysis. This solves issues where OpenCV cannot correctly read the frames of older formats.

**Notes:**
- Checks for existing `localPath` or downloads the video if missing.
- Runs `PySceneDetect` via the Python integration.
- Outputs a list of detected scenes with start/end times.
- Prompts to save the detected scenes to the database.
- If only 1 scene is detected for a long video, try using `--convert` and `--detector adaptive`.

### `app:test-analyze`
Triggers the full download and frame splitting process for a given YouTube URL. Primarily used for development and testing.

**Usage:**
```bash
php bin/console app:test-analyze <url> [videoId]
```
-   `url`: The YouTube URL to process.
-   `videoId`: (Optional) The ID to associate with the process (defaults to 999).

### `app:test-frame-analyze`
Analyzes a single specific frame. This is a hardcoded test command used to verify the AWS Rekognition integration for a single image file.

**Usage:**
```bash
php bin/console app:test-frame-analyze
```

---

## Symfony Messenger Worker
While not a custom command of this project, it is essential for the asynchronous processing of videos.

**Usage:**
```bash
php bin/console messenger:consume async -vv
```
-   Processes the messages dispatched by the commands above or the web interface.
