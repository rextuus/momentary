# Project Architecture

Momentary follows a decoupled architecture where the heavy lifting of video processing is handled by Python scripts, while the business logic and user interface are managed by a Symfony application.

## High-Level Components

### 1. Symfony Web Application (`src/`)
The core of the system. It handles:
-   **User Interface**: Managing videos and persons.
-   **Task Orchestration**: Dispatching messages to the Symfony Messenger bus for asynchronous processing.
-   **Data Persistence**: Storing metadata about videos, scenes, faces, and persons in the database.
-   **AWS Integration**: Communicating with Amazon Rekognition via the AWS SDK for PHP.

### 2. Python Processing Service (`video-analyzer/python/`)
A set of specialized scripts for media handling:
-   `download_video.py`: Downloads videos (e.g., from YouTube).
-   `detect_scenes.py`: Analyzes video content to find scene changes.
-   `extract_frames.py`: Captures frames from the video at specific intervals for face analysis.

### 3. Amazon Rekognition
Cloud service used for:
-   **Face Indexing**: Storing face vectors in a collection.
-   **Face Searching**: Comparing newly detected faces against existing ones in the collection to identify people.
-   **Attribute Extraction**: Getting age, gender, and emotion for each face.

## Communication Flow

1.  User adds a video via the Symfony Dashboard.
2.  Symfony dispatches an `InitVideoMessage`.
3.  The `VideoAnalyzer` service calls Python scripts via Symfony `Process`.
4.  Extracted frames are sent to the `FrameAnalyzerMessageHandler`.
5.  AWS Rekognition is called to analyze each frame.
6.  Results are stored in the database and linked to `Person` entities.
