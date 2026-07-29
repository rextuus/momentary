# Projekt-Architektur

Momentary verfolgt eine entkoppelte Architektur, bei der die rechenintensiven Videoverarbeitungsschritte von Python-Skripten übernommen werden, während die Geschäftslogik und die Benutzeroberfläche von einer Symfony-Anwendung verwaltet werden.

## High-Level Komponenten

### 1. Symfony Web Application (`src/`)
Der Kern des Systems. Er steuert:
-   **Benutzeroberfläche**: Verwaltung von Videos und Personen.
-   **Task-Orchestrierung**: Dispatching von Nachrichten an den Symfony Messenger-Bus für die asynchrone Verarbeitung.
-   **Datenpersistenz**: Speicherung von Metadaten über Videos, Szenen, Gesichter und Personen in der Datenbank.
-   **AWS-Integration**: Kommunikation mit Amazon Rekognition über das AWS SDK für PHP.

### 2. Python Processing Service (`video-analyzer/python/`)
Spezialisierte Skripte für die Medienverarbeitung, die von Symfony via `Process`-Komponente aufgerufen werden:
-   `convert_to_mp4.py`: Umwandlung von Videodateien in kompatible Formate.
-   `detect_scenes.py`: Analyse des Videostreams zur automatischen Szenenerkennung.
-   `extract_frames.py`: Extraktion von Frames für die Analyse.
-   `analyze_frame.py`: Analyse einzelner Bilder, z.B. für Gesichtsanalysen.

### 3. Jellyfin Media Server
Ermöglicht das Streamen und Betrachten der verarbeiteten Videos:
-   **Export**: Abgeschlossene Videos werden in ein von Jellyfin überwachtes Verzeichnis verschoben.
-   **API-Integration**: Momentary triggert automatisch einen Library-Scan in Jellyfin nach dem Export.

### 4. Amazon Rekognition
Cloud-Dienst für:
-   **Face Indexing**: Speicherung von Gesichtsvektoren in einer Collection.
-   **Face Searching**: Identifizierung von Personen durch Vergleich erkannter Gesichter.
-   **Attribute Extraction**: Analyse von Alter, Geschlecht und Emotionen.

### 5. Tagging & KI-Integration
-   **Google Gemini**: Wird als KI-Modell für die automatische, inhaltsbasierte Verschlagwortung (Tagging) der Szenen und die Kapitelgenerierung eingesetzt.
-   **Amazon Rekognition**: Unterstützt bei der Erkennung und Analyse von Gesichtern.

## Kommunikationsfluss
Die Symfony-Anwendung steuert den Prozess über den `messenger-worker`:
1.  **Upload**: Das Video wird als MP4 in `public/uploads/import/` abgelegt (manuell via SFTP oder Upload-Formular). Nur MP4-Dateien werden im Formular `/video/new` angezeigt.
2.  **Dispatching**: Beim Absenden des Formulars dispatcht Symfony `ConvertVideoMessage` → der Worker startet die Pipeline.
3.  **Worker-Ausführung**: Der `messenger-worker` verarbeitet die Nachrichten asynchron und führt bei Bedarf Python-Skripte aus.
4.  **KI-Analysen**: Für Szenenanalysen und das Tagging bindet der Workflow Amazon Rekognition (Gesichter) und Google Gemini (Tags, Kapitel) ein.
5.  **Datenpersistenz**: Ergebnisse werden in MySQL gespeichert und für das Frontend (Suche via Meilisearch) bereitgestellt.

## Infrastruktur (Docker Compose)
Das System besteht aus mehreren Containern, die via `docker-compose` orchestriert werden:

-   **app**: Die Symfony-Hauptanwendung (PHP-FPM/Apache).
-   **messenger-worker**: Ein separater Container, der Symfony Messenger Nachrichten konsumiert, um die zeitaufwändige Videoverarbeitung asynchron abzuarbeiten.
-   **database**: Eine MySQL-Datenbank zur Speicherung aller Applikationsdaten.
-   **rabbitmq**: Message Broker für das asynchrone Messaging zwischen `app` und `messenger-worker`.
-   **meilisearch**: Suchmaschine für schnelle Suchen nach Personen/Szenen.
-   **imgproxy**: Optimiert Bilder für die Darstellung im Frontend. Liest Dateien aus `public/` (gemountet als `/public`).
-   **jellyfin**: Media-Server zur Anzeige der fertigen Videos.
-   **mailer**: Mailpit zum Testen von E-Mails in der Entwicklung.

## Verarbeitungsprozess (Workflow)
Die Videoverarbeitung wird durch eine Symfony **State Machine** (`video_processing`) gesteuert. Der Workflow durchläuft folgende Zustände in dieser Reihenfolge:

1.  **PENDING** → Video registriert, wartet auf Start.
2.  **CONVERTING** → `ConvertVideoMessage`: Konvertierung falls nötig (bei MP4 wird dieser Schritt übersprungen). Danach immer `DetectVideoScenesMessage`.
3.  **SCENE_DETECTION** → `DetectVideoScenesMessage`: Szenenerkennung via Python-Skript. Danach `ExtractAllSceneThumbnailsMessage` (wenn Szenen gefunden) oder direkt `SplitVideoIntoFramesMessage` (wenn keine Szenen).
4.  **EXTRACTING_THUMBNAILS** → `ExtractAllSceneThumbnailsMessage` / `ExtractSceneThumbnailMessage`: Für jede Szene wird ein Thumbnail extrahiert und gespeichert. Danach `SplitVideoIntoFramesMessage`.
5.  **SPLITTING** → `SplitVideoIntoFramesMessage`: Das Video wird in Frames aufgeteilt (Standard: 5s-Intervalle). Für jeden Frame wird `FrameAnalyzerMessage` dispatcht.
6.  **ANALYZING_FACES** → `FrameAnalyzerMessage`: Frames werden an Amazon Rekognition zur Gesichtserkennung geschickt. Nach dem letzten Frame: `SplitVideoIntoFramesMessage` (Refinement, 1s-Intervalle für Szenen ohne erkannte Personen).
7.  **REFINING_EXTRACTION** → `SplitVideoIntoFramesMessage` (isRefinement=true): Szenen ohne Personen werden in 1s-Frames aufgeteilt.
8.  **REFINING_ANALYSIS** → `FrameAnalyzerMessage` (isRefinement=true): Verfeinerte Frames werden erneut analysiert. Nach dem letzten Frame: `VideoAnalyzer::mergeEmptyScenes()` → `OptimizeVideoForJellyfinMessage`.
9.  **MERGING_SCENES** → `VideoAnalyzer::mergeEmptyScenes()`: Szenen ohne erkannte Personen werden mit der nächsten Szene zusammengeführt. Danach `OptimizeVideoForJellyfinMessage`.
10. **OPTIMIZING** → `OptimizeVideoForJellyfinMessage`: Vorbereitung für Jellyfin (bei MP4 wird die eigentliche Optimierung übersprungen). Danach `TagScenesMessage` (wenn `ENABLE_TAGGING_SCENES=true`) oder `ExportVideoToJellyfinMessage`.
11. **TAGGING_SCENES** → `TagScenesMessage` / `AnalyzeSceneMessage`: Für jede Szene wird das Thumbnail an Google Gemini geschickt und Tags + Titel generiert. Nach dem letzten getaggten Szene: `GenerateChaptersMessage`.
12. **CHAPTER_GENERATION** → `GenerateChaptersMessage`: Aus den gesammelten Tags und Szenen werden Kapitel mit Gemini generiert. Danach `ExportVideoToJellyfinMessage`.
13. **COMPLETED** → `ExportVideoToJellyfinMessage`: Das Video wird in das Jellyfin-Verzeichnis exportiert und ein Library-Scan getriggert.

Fehler während des Prozesses führen in den Status **ERROR**.

## Datei-Ablage (Flysystem)
Alle Uploads werden unter `public/uploads/` gespeichert:
-   **Import-Videos**: `public/uploads/import/{filename}` — Quelldateien
-   **Video-Assets** (Thumbnails, Frames, Faces): `public/uploads/{VideoName}_{hash}/{thumbnails,frames,faces}/`
-   **Defaults**: `public/uploads/defaults/` — Platzhalterbilder

Flysystem ist so konfiguriert, dass alle Dateien mit `0644` und Verzeichnisse mit `0755` angelegt werden, damit imgproxy lesend zugreifen kann.
