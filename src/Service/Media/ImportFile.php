<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * Eine Datei im Import-Ordner.
 */
final readonly class ImportFile
{
    public function __construct(
        /** relativ zum Import-Ordner, z. B. "Mallorca/VIDEO/20250319_121537.MOV" */
        public string $relativePath,
        /** MediaImporter::TYPE_* */
        public string $type,
        public int $size,
        public \DateTimeImmutable $modifiedAt,
        /** erster Unterordner: wird zum Gruppen-Tag */
        public ?string $batch,
        /** seit einer Weile unverändert, also nicht mehr im Upload (SFTP) */
        public bool $ready,
        /**
         * Gleiche Größe wie ein importiertes Original oder Zeitstempel im Namen wie die Kamerazeit eines vorhandenen
         * Mediums: vermutlich doppelt. Sicher erkannt werden Doppelte nur per Hash, und den haben ältere Videos nicht.
         */
        public bool $likelyDuplicate,
    ) {}

    public function getName(): string
    {
        return basename($this->relativePath);
    }
}
