<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Ton eines Videos transkribieren. Läuft über eine eigene Queue, damit die stundenlange CPU-Arbeit
 * die normale Video-Pipeline nicht blockiert.
 */
final readonly class TranscribeVideoMessage
{
    public function __construct(
        public int $videoId,
    ) {}
}
