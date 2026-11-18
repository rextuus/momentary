# Console Commands

Momentary bietet folgende Konsolenbefehle zur Verwaltung der Anwendung, der Videoverarbeitungspipeline und der Infrastruktur.

## Admin & System
- `app:admin:create` (`App\Command\Admin\CreateAdminCommand`): Erstellt den ersten Administrator-Benutzer.
- `app:user:create <E-Mail> [--role=family|editor|admin] [--pool=<Name|ID> …] [--password=…] [--reset-password]` (`App\Command\Admin\CreateUserCommand`): Legt ein Konto an (Standard: Familie) und nimmt es in Pools auf; Passwort wird verdeckt abgefragt, leer = gut lesbares erzeugen. `--reset-password` setzt bei vorhandenem Konto nur das Passwort neu.
- `app:admin:queue-clear` (`App\Command\Admin\QueueClearCommand`): Leert die Messenger-Queue.
- `app:admin:storage-clear` (`App\Command\Admin\StorageClearCommand`): Bereinigt den Speicher.
- `app:admin:storage-reset` (`App\Command\Admin\StorageResetCommand`): Setzt den Speicher zurück.
- `app:database:reset` (`App\Command\Database\DatabaseResetCommand`): Setzt die Datenbank zurück.
- `app:system:reset` (`App\Command\System\FullResetCommand`): Führt einen vollständigen System-Reset durch.
- `app:version` (`App\Command\AppVersionCommand`): Zeigt die aktuelle Anwendungsversion an.

## Videoverarbeitung & Pipeline
- `app:video:processing-pipeline:show` (`App\Command\ShowVideoProcessingPipelineCommand`): Zeigt die Struktur der aktuellen Videoverarbeitungspipeline.
- `app:files:move-uploaded` (`App\Command\Files\MoveUploadedFilesCommand`): Verschiebt Dateien aus den SFTP-Upload-Verzeichnissen in den Import-Ordner.
- `app:media:import-folder [--owner=mail] [--list]` (`App\Command\Media\ImportFolderCommand`): Übernimmt Videos und Fotos aus dem Import-Ordner (siehe `documentation/media.md`, „Upload & Import-Ordner“).
- `app:video:import <Datei>`, `app:photo:import <Datei|Ordner>…`: Einzelne Dateien bzw. Fotos direkt importieren.
- `app:photo:bursts`: Fehlende Fingerabdrücke berechnen und Serienfotos neu bilden.
- `app:images:init-profiles` (`App\Command\Images\InitProfileImagesCommand`): Initialisiert Profile-Bilder (cachedImageUrl).

## Meilisearch (Suche)
- `app:meilisearch:clear` (`App\Command\Meilisearch\ClearIndexCommand`): Löscht den Meilisearch-Index.
- `app:meilisearch:index` (`App\Command\Meilisearch\IndexCommand`): Indiziert ein einzelnes Video anhand der ID.
- `app:meilisearch:index-all` (`App\Command\Meilisearch\IndexAllVideosCommand`): Indiziert alle Videos neu.
- `app:meilisearch:list-indexes` (`App\Command\Meilisearch\ListIndexesCommand`): Listet alle Meilisearch-Indizes auf.

## Gesichtserkennung
- `app:face:analyze <videoId> [--fps=…] [--mode=fps|scenes] [--limit=N] [--two-stage[=320]] [--frames] [--save]` (`App\Command\Faces\AnalyzeFacesCommand`): Ein Video mit InsightFace messen: Laufzeit je Frame, Gesichter, Verteilung der besten Ähnlichkeiten, zugeordnet/vorgeschlagen/unbekannt bei den aktuellen Schwellen; mit `--two-stage` zusätzlich der Vorfilter im Vergleich. Standard nur messen; `--save` ersetzt die Gesichter des Videos (siehe `face_recognition.md`).
- `app:faces:consolidate <videoId>|--all` (`App\Command\Faces\ConsolidateFacesCommand`): Gesichter mit den aktuellen Einstellungen neu gruppieren, ohne neue Analyse.
- `app:persons:cleanup-orphans` (`App\Command\Faces\CleanupOrphanedPersonsCommand`): Automatisch angelegte Personen ohne Gesicht entfernen.
- `app:video:source-height <videoId> [Zeilen]` (`App\Command\Video\SourceHeightCommand`): Native Bildhöhe anzeigen oder setzen (z. B. 576 für ein schon hochskaliert importiertes PAL-Band); Bezug der Mindestgröße.
- `app:video:cleanup-originals [--dry-run] [--include-interlaced]` (`App\Command\CleanupOriginalsCommand`): Originale fertiger Videos und Analyse-Frames löschen; interlaced Originale bleiben, außer mit `--include-interlaced`.

## AWS Rekognition & Tags
- `app:rekognition:list-faces` (`App\Command\Rekognition\ListRekognitionFacesCommand`): Listet alle in Rekognition erkannten Gesichter auf.
- `app:rekognition:reset` (`App\Command\Rekognition\ResetRekognitionCommand`): Setzt die Rekognition-Konfiguration zurück.
- `app:rekognition:setup` (`App\Command\Rekognition\SetupRekognitionCommand`): Initialisiert die Rekognition-Collection.
- `app:tags:cleanup` (`App\Command\Tags\CleanupTagsCommand`): Bereinigt Tags.
- `app:tags:dispatch` (`App\Command\Tags\DispatchTaggingCommand`): Startet den Tagging-Prozess.

## Symfony Messenger Worker
Für die asynchrone Verarbeitung ist der Messenger-Worker erforderlich:
```bash
php bin/console messenger:consume async -vv
```
