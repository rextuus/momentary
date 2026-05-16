# Database Schema

The system uses four main entities to manage video data and recognized faces.

## Entity Relationship Diagram (Mental Model)

-   **Video** (1) <---> (N) **VideoScene**
-   **Video** (1) <---> (N) **VideoFace**
-   **VideoScene** (1) <---> (N) **VideoFace**
-   **Person** (1) <---> (N) **VideoFace**

## Entities

### Video
Stores general information about the video source.
-   `title`: Name of the video.
-   `youtubeUrl`: Source link (nullable).
-   `sourceFile`: Name of the local file for import (nullable).
-   `localPath`: Path to the downloaded or imported file.
-   `status`: Current processing state (PENDING, DOWNLOADING, ANALYZING, COMPLETED, etc.).

### VideoScene
Represent segments of a video.
-   `sceneNumber`: Chronological order.
-   `startSeconds` / `endSeconds`: Time bounds.
-   `video`: Reference to the parent Video.

### Person
Represents a unique individual across all videos.
-   `name`: Human-readable name.
-   `isIdentified`: Boolean flag.
-   `videoFaces`: Collection of all detected instances of this person.

### VideoFace
A specific occurrence of a face in a frame.
-   `timestamp`: When the face appeared.
-   `faceImagePath`: Path to the cropped face image or frame.
-   `faceLabel`: The AWS Rekognition FaceId.
-   `boundingBox`: JSON data for UI highlighting.
-   `age`, `gender`, `emotion`: Extracted attributes.
-   `person`: Link to the identified Person entity.
-   `videoScene`: Link to the specific scene where the face was found.
