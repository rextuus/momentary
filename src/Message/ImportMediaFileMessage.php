<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Eine Datei aus dem Import-Ordner übernehmen (Hash, Kopie, Analyse). Läuft über die Queue "async".
 */
final readonly class ImportMediaFileMessage
{
    public function __construct(
        /** relativ zum Import-Ordner */
        public string $relativePath,
        public ?int $ownerId = null,
    ) {}
}
