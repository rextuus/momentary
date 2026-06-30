# Momentary - Home Video Management & Face Recognition

Welcome to the Momentary documentation. This project is designed to help you manage old home videos by automatically identifying people using Amazon Rekognition.

## Table of Contents

1.  [Project Overview](architecture.md)
2.  [Video Processing Pipeline](processing_pipeline.md)
3.  [Database Schema & Entities](database_schema.md)
4.  [Console Commands](console_commands.md)
5.  [Setup & Configuration](setup.md)
6.  [Jellyfin Integration](jellyfin_integration.md)
7.  [Alternativen zu Jellyfin](alternatives.md)

## Key Features

-   **Video Import**: Support for YouTube URLs (and potentially local files).
-   **Scene Detection**: Automatically splits videos into logical scenes using PySceneDetect.
-   **Face Identification**: Uses Amazon Rekognition to detect and identify persons across different videos.
-   **Person Management**: Group recognized faces into unique person entities.
-   **Timeline View**: Visualize which persons appear in which scenes of a video.

## Technology Stack

-   **Backend**: Symfony (PHP 8.2+)
-   **Database**: PostgreSQL / MySQL (Doctrine ORM)
-   **Video Processing**: Python 3 with OpenCV and PySceneDetect
-   **Cloud Services**: Amazon Rekognition
-   **Frontend**: Symfony UX / Twig / Tailwind CSS
