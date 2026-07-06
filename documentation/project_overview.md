# Projektübersicht: Momentary

## 1. Was ist Momentary?
Momentary ist ein System zur automatisierten Analyse und Katalogisierung von Videomaterial, insbesondere für Familienarchive. Es identifiziert Szenen, erkennt bekannte Gesichter, ordnet diese Personen zu und ermöglicht den Export der angereicherten Metadaten in Mediensysteme wie Jellyfin.

Das Ziel ist es, große Mengen an Videomaterial durchsuchbar zu machen, indem automatisch erkannt wird, wer wann in welchem Video zu sehen ist.

## 2. Tech Stack
- **Backend:** PHP 8.5+ mit Symfony 8.0 (Full-Stack Framework)
- **Datenbank:** MySQL 8.0 (verwaltet über Doctrine ORM)
- **Frontend:** Twig-Templates, Symfony UX (Twig Components, Live Components), Sass für Styling, Webpack Encore
- **Asynchrone Verarbeitung:** Symfony Messenger (mit Doctrine-Transport)
- **KI & Analyse:**
    - **AWS Rekognition:** Zur Gesichtserkennung, Altersbestimmung, Emotionserkennung und Identifizierung von Personen in einer Face-Collection.
    - **PySceneDetect:** Integration (via Python) zur automatischen Erkennung von Szenenübergängen.
    - **FFmpeg:** Zur Frame-Extraktion und Videokonvertierung.
- **Infrastruktur:**
    - Docker (App-Container, Datenbank, Jellyfin für Tests, Mailpit).
    - Flysystem für die Abstraktion des Dateisystems.
- **Integrationen:** Jellyfin (Video-Streaming-Server) als Exportziel für Metadaten.

## 3. Hauptfunktionen
- **Video-Import:** Upload von lokalen Dateien oder Download via YouTube-URL.
- **Workflow-Management:** Ein zustandsbasierter Prozess (Symfony Workflow), der Videos durch verschiedene Stadien leitet (Downloading -> Scene Detection -> Analyzing Faces -> Completed).
- **Szenenerkennung:** Automatisches Splitten von Videos in logische Szenen.
- **Gesichtserkennung:** Extraktion von Frames aus Szenen und Analyse durch AWS Rekognition.
- **Personen-Management:**
    - Zuordnung von erkannten Gesichtern zu Personen.
    - Auflösung von Unklarheiten (Tinder-ähnliches Interface zum schnellen Bestätigen von Personen).
    - Zusammenführen von Personenprofilen.
- **Timeline-Ansicht:** Visualisierung, welche Personen zu welchem Zeitpunkt im Video erscheinen.
- **Jellyfin-Export:** Export der erkannten Kapitel (Szenen) und Tags an Jellyfin.

## 4. Kernkomponenten (src/)
- `App\Entity`: Datenmodell (Video, Scene, Person, Tag, Face). Eine detaillierte Übersicht findest du in [entity_overview.md](entity_overview.md).
- `App\Service\VideoAnalyzer`: Das Herzstück der Analyse-Logik (Szenen, Frames, AWS-Anbindung).
- `App\Service\WorkflowMachine`: Steuerung des Video-Status.
- `App\Service\Aws\AmazonRekognitionService`: Interface zur AWS Cloud.
- `App\MessageHandler`: Asynchrone Handler für rechenintensive Aufgaben.
- `App\Twig\Components`: Moderne UI-Komponenten für die Personenverwaltung und Timeline.

## 5. Workflow eines Videos
1. **Pending:** Video ist registriert.
2. **Downloading / Converting:** Rohmaterial wird beschafft und in ein bearbeitbares Format gebracht.
3. **Scene Detection:** PySceneDetect markiert Starts und Enden von Szenen.
4. **Splitting / Analyzing:** Frames werden extrahiert und an AWS Rekognition gesendet.
5. **Refining:** (Optional) Höher auflösende Analyse bei Bedarf.
6. **Completed:** Video ist analysiert und bereit für den Export oder die manuelle Korrektur.
