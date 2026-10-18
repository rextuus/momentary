<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Foto aufbereiten und analysieren (EXIF, Gesichter, Tags). Läuft über die Queue "photos".
 */
final readonly class AnalyzePhotoMessage
{
    public function __construct(
        public int $photoId,
    ) {}
}
