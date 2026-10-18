<?php

declare(strict_types=1);

namespace App\Service\Photo;

use App\Entity\Photo;

final readonly class PhotoImportResult
{
    public function __construct(
        public Photo $photo,
        /** Datei war schon importiert; $photo ist das vorhandene Foto */
        public bool $duplicate,
    ) {}
}
