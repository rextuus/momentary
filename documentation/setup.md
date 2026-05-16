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
    Since video processing is asynchronous, you need to run the messenger worker:
    ```bash
    php bin/console messenger:consume async -vv
    ```
