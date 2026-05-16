# Video Processing Pipeline

The processing of a video is a multi-step asynchronous workflow.

## Step 1: Initialization
When a video is submitted via the web interface:
-   **If YouTube URL provided**: An `InitVideoMessage` is dispatched to download the video.
-   **If local file selected**: A `DetectVideoScenesMessage` is dispatched immediately using the file from `public/uploads/import`.

## Step 2: Downloading & Scene Detection (or just Scene Detection)
-   **Download**: (If via YouTube) `download_video.py` uses `yt-dlp` to fetch the video.
-   **Scenes**: `detect_scenes.py` uses `PySceneDetect` to identify transitions. Scenes are stored in the `video_scene` table.

## Step 3: Frame Extraction
-   `extract_frames.py` takes snapshots of the video at a defined interval (e.g., 0.2 FPS).
-   Each frame path is dispatched via a `FrameAnalyzerMessage`.

## Step 4: Face Analysis (AWS Rekognition)
The `FrameAnalyzerMessageHandler` processes each frame:
1.  **Face Detection**: Finds all faces in the frame.
2.  **Face Search**: Checks if the face exists in the AWS Rekognition collection.
3.  **Entity Mapping**:
    -   If a match is found, the face is linked to the existing `Person`.
    -   If no match is found, a new `Person` is created (marked as unidentified).
4.  **Metadata Storage**: Age, gender, emotion, and bounding box coordinates are saved in the `video_face` table.

## Step 5: Completion & Cleanup
Once the last frame is processed, the video status is set to `COMPLETED`.
If a `youtubeUrl` is present, the local video file is deleted to save space. If the `youtubeUrl` is added after the analysis is complete, the cleanup is triggered at that moment.
The user can then review identified persons and manually resolve "unknown" persons.
