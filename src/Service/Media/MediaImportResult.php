<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\Medium;

final readonly class MediaImportResult
{
    public function __construct(
        public Medium $medium,
        /** Datei war schon importiert; $medium ist das vorhandene Video bzw. Foto */
        public bool $duplicate,
        /** Gruppen-Tag (Kategorie „Upload-Ordner“), falls gesetzt */
        public ?string $tag = null,
    ) {}
}
