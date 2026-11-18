<?php

declare(strict_types=1);

namespace App\Service\Face\Embedding;

/**
 * Ein gespeichertes Gesicht, das als Referenz seiner Person in Frage kommt (ohne Embedding, das kommt erst danach).
 */
final readonly class ReferenceCandidate
{
    /**
     * @param float|null $detectionScore 0–100
     * @param int|null   $year           Aufnahmejahr des Mediums; null = unbekannt
     */
    public function __construct(
        public int $faceId,
        public int $personId,
        public bool $verified,
        public ?float $detectionScore,
        public ?int $sizePx,
        public ?int $year = null,
    ) {}
}
