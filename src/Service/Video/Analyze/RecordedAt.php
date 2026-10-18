<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

/**
 * Aufnahmezeitpunkt (UTC) mit Herkunft (Medium::DATE_*).
 */
final readonly class RecordedAt
{
    public function __construct(
        public \DateTimeImmutable $date,
        public string $source,
    ) {}
}
