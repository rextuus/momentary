<?php

declare(strict_types=1);

namespace App\Service\Video\Recording;

/**
 * Aufeinanderfolgende Frames einer DV-Datei mit fortlaufender Kamerazeit (oder durchgehend ohne), wie
 * read_dv_subcodes.py sie liefert.
 */
final readonly class DvRun
{
    /**
     * @param \DateTimeImmutable|null $cameraTime Kamerazeit des ersten Frames als Ortszeit (Europe/Berlin), ungeprüft
     * @param bool                    $recStart   Kamera hat hier den Beginn einer Aufnahme markiert
     */
    public function __construct(
        public int $startFrame,
        public int $endFrame,
        public ?\DateTimeImmutable $cameraTime,
        public bool $recStart,
    ) {}
}
