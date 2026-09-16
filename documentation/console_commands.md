# Console Commands

Momentary bietet folgende Konsolenbefehle zur Verwaltung der Anwendung, der Videoverarbeitungspipeline und der Infrastruktur.

## Admin & System
- `app:admin:create` (`App\Command\Admin\CreateAdminCommand`): Erstellt den ersten Administrator-Benutzer.
- `app:admin:queue-clear` (`App\Command\Admin\QueueClearCommand`): Leert die Messenger-Queue.
- `app:admin:storage-clear` (`App\Command\Admin\StorageClearCommand`): Bereinigt den Speicher.
- `app:admin:storage-reset` (`App\Command\Admin\StorageResetCommand`): Setzt den Speicher zurück.
- `app:database:reset` (`App\Command\Database\DatabaseResetCommand`): Setzt die Datenbank zurück.
- `app:system:reset` (`App\Command\System\FullResetCommand`): Führt einen vollständigen System-Reset durch.
- `app:version` (`App\Command\AppVersionCommand`): Zeigt die aktuelle Anwendungsversion an.

## Videoverarbeitung & Pipeline
- `app:video:processing-pipeline:show` (`App\Command\ShowVideoProcessingPipelineCommand`): Zeigt die Struktur der aktuellen Videoverarbeitungspipeline.
- `app:files:move-uploaded` (`App\Command\Files\MoveUploadedFilesCommand`): Verschiebt Dateien aus SFTP-Upload-Verzeichnissen in die Import-Verzeichnisse.
- `app:images:init-profiles` (`App\Command\Images\InitProfileImagesCommand`): Initialisiert Profile-Bilder (cachedImageUrl).

## Meilisearch (Suche)
- `app:meilisearch:clear` (`App\Command\Meilisearch\ClearIndexCommand`): Löscht den Meilisearch-Index.
- `app:meilisearch:index` (`App\Command\Meilisearch\IndexCommand`): Indiziert ein einzelnes Video anhand der ID.
- `app:meilisearch:index-all` (`App\Command\Meilisearch\IndexAllVideosCommand`): Indiziert alle Videos neu.
- `app:meilisearch:list-indexes` (`App\Command\Meilisearch\ListIndexesCommand`): Listet alle Meilisearch-Indizes auf.

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
