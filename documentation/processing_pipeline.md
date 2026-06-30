# Video Processing Pipeline

The processing of a video is managed by a **Symfony Workflow (State Machine)** to ensure consistent status transitions.

## Step 0: Workflow Machine
All status changes are handled by the `App\Service\WorkflowMachine`. It uses transitions to move from one state to another, preventing invalid states.

## Step 1: Initialization & Upload
When a video is submitted via the web interface:
-   **If YouTube URL provided**: Transition `start_download` is applied and a `DownloadVideoMessage` is dispatched.
-   **If local file selected**: Transition `start_conversion` is applied and a `ConvertVideoMessage` is dispatched.
-   **Direct Upload**: Files can be uploaded via the "Upload" page directly into `public/uploads/import`, making them available for selection.

## Step 2: Downloading & Scene Detection (or just Scene Detection)
-   **Download**: (If via YouTube) `download_video.py` uses `yt-dlp` to fetch the video. Status changes to `DOWNLOADING`.
-   **Scenes**: `detect_scenes.py` uses `PySceneDetect` to identify transitions. Status changes to `SCENE_DETECTION`. Scenes are stored in the `video_scene` table.

## Step 3: Frame Extraction
-   Status changes to `SPLITTING`. `extract_frames.py` takes snapshots of the video at a defined interval (e.g., 0.2 FPS).
-   Each frame path is dispatched via a `FrameAnalyzerMessage`.

## Step 4: Face Analysis (AWS Rekognition)
The `FrameAnalyzerMessageHandler` processes each frame:
1.  **Status**: Video is in `ANALYZING_FACES` state.
2.  **Face Detection**: Finds all faces in the frame.
3.  **Face Search**: Checks if the face exists in the AWS Rekognition collection.
4.  **Entity Mapping**:
    -   If a match is found, the face is linked to the existing `Person`.
    -   If no match is found, a new `Person` is created (marked as unidentified).
5.  **Metadata Storage**: Age, gender, emotion, and bounding box coordinates are saved in the `video_face` table.

## Step 5: Completion & Cleanup
Once the last frame is processed, the transition `complete` is applied and the video status is set to `COMPLETED`.
If a `youtubeUrl` is present, the local video file is deleted to save space. If the `youtubeUrl` is added after the analysis is complete, the cleanup is triggered at that moment.

## Workflow Transitions
The workflow supports the following main transitions:
- `start_download`: PENDING -> DOWNLOADING
- `start_conversion`: DOWNLOADING/PENDING -> CONVERTING
- `start_scene_detection`: CONVERTING/PENDING/DOWNLOADING -> SCENE_DETECTION
- `start_splitting`: SCENE_DETECTION -> SPLITTING
- `start_analyzing`: SPLITTING -> ANALYZING_FACES
- `start_refining_extraction`: ANALYZING_FACES -> REFINING_EXTRACTION
- `start_refining_analysis`: REFINING_EXTRACTION -> REFINING_ANALYSIS
- `start_merging`: REFINING_ANALYSIS -> MERGING_SCENES
- `start_optimization`: COMPLETED/ERROR/MERGING_SCENES/ANALYZING_FACES -> OPTIMIZING
- `complete`: MERGING_SCENES/ANALYZING_FACES/SCENE_DETECTION/OPTIMIZING -> COMPLETED
- `fail`: ANY -> ERROR
- `reset`: ANY -> PENDING

### Manuelle Steuerung und Rücksprünge
Die Pipeline ermöglicht das gezielte Zurücksetzen auf bestimmte Schritte ("Ab hier neu starten"). Dies wird über dedizierte Rücksprung-Transitionen gelöst, die eine saubere Historie und Statusvalidität gewährleisten:
- `back_to_conversion`: Ermöglicht den Sprung zurück zum Anfang der Verarbeitung (Konvertierung).
- `back_to_scene_detection`: Ermöglicht den Sprung zurück zur Szenenerkennung aus allen späteren Stadien.
- `back_to_splitting`: Setzt den Workflow auf den Stand vor der Frame-Extraktion zurück.
- `back_to_refining_extraction`: Ermöglicht die Korrektur der Verfeinerungs-Phase.

Diese Rücksprünge stellen sicher, dass alle nachfolgenden Daten konsistent neu generiert werden können, ohne den Workflow zu korrumpieren.

### Step 6: Export (Optional)
Wenn konfiguriert, kann das Video zu Jellyfin exportiert werden. Dieser Prozess beinhaltet:
- **Optimierung**: Konvertierung zu MP4 via Python/FFmpeg.
- **Transfer**: Kopieren in das Jellyfin-Verzeichnis.
- **Scan**: Triggerung der Jellyfin-API.
- **ID-Mapping**: Verknüpfung der Jellyfin-ID für den internen Player.

Siehe [Jellyfin Integration](jellyfin_integration.md) für Details.
