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
-   **Google Gemini**: Wird als KI-Modell für die automatische, inhaltsbasierte Verschlagwortung (Tagging) der Szenen eingesetzt.
-   **Amazon Rekognition**: Unterstützt bei der Erkennung und Analyse von Gesichtern.

## Kommunikationsfluss
Die Symfony-Anwendung steuert den Prozess über den `messenger-worker`:
1.  **Dispatching**: Wenn ein Video hinzugefügt wird, dispatcht Symfony entsprechende Nachrichten (z.B. `DownloadVideoMessage`, `DetectVideoScenesMessage`) in die RabbitMQ-Queue.
2.  **Worker-Ausführung**: Der `messenger-worker` verarbeitet diese Nachrichten asynchron und führt bei Bedarf die Python-Skripte aus.
3.  **KI-Analysen**: Für Szenenanalysen und das Tagging bindet der Workflow Dienste wie Amazon Rekognition (für Gesichter) und Google Gemini (für inhaltliche Tags) ein.
4.  **Datenpersistenz**: Ergebnisse der Analysen (Tags, Gesichter, Szenenmetadaten) werden in der MySQL-Datenbank gespeichert und für das Frontend (und Suche über Meilisearch) bereitgestellt.

## Infrastruktur (Docker Compose)
Das System besteht aus mehreren Containern, die via `docker-compose` orchestriert werden:

-   **app**: Die Symfony-Hauptanwendung (PHP-FPM/Apache).
-   **messenger-worker**: Ein separater Container, der Symfony Messenger Nachrichten konsumiert, um die zeitaufwändige Videoverarbeitung asynchron abzuarbeiten.
-   **database**: Eine MySQL-Datenbank zur Speicherung aller Applikationsdaten.
-   **rabbitmq**: Message Broker für das asynchrone Messaging zwischen `app` und `messenger-worker`.
-   **meilisearch**: Suchmaschine für schnelle Suchen nach Personen/Szenen.
-   **imgproxy**: Optimiert Bilder für die Darstellung im Frontend.
-   **jellyfin**: Media-Server zur Anzeige der fertigen Videos.
-   **mailer**: Mailpit zum Testen von E-Mails in der Entwicklung.

## Verarbeitungsprozess (Workflow)
Die Videoverarbeitung wird durch eine Symfony **State Machine** (`video_processing`) gesteuert. Der Workflow durchläuft folgende Zustände:

1.  **PENDING**: Video registriert, wartet auf Start.
2.  **CONVERTING**: Video-Konvertierung in ein kompatibles Format.
3.  **SCENE_DETECTION**: Szenenerkennung.
4.  **EXTRACTING_THUMBNAILS**: Extraktion von Vorschaubildern.
5.  **SPLITTING**: Zerteilen des Videos in Szenen.
6.  **ANALYZING_FACES**: Gesichteranalyse mittels AWS Rekognition.
7.  **REFINING_EXTRACTION** & **REFINING_ANALYSIS**: Verfeinerung der Analyse.
8.  **MERGING_SCENES**: Zusammenführen der Szenen.
9.  **OPTIMIZING**: Vorbereitung für Jellyfin.
10. **TAGGING_SCENES**: Automatische Verschlagwortung.
11. **CHAPTER_GENERATION**: Kapitelmarken-Generierung.
12. **COMPLETED**: Abschluss.

Fehler während des Prozesses führen in den Status **ERROR**.
