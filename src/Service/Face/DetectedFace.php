<?php

declare(strict_types=1);

namespace App\Service\Face;

/**
 * Ein in einem Bild erkanntes Gesicht, unabhängig vom Anbieter (Rekognition oder InsightFace).
 *
 * Die Zuordnung zu Personen arbeitet nur mit faceId und candidates: candidates sind andere, schon gespeicherte
 * Gesichter (VideoFace::faceLabel) mit ihrer Ähnlichkeit auf einer Skala von 0 bis 100.
 */
final readonly class DetectedFace
{
    /**
     * @param array<string, float> $boundingBox relative Box mit Left/Top/Width/Height
     * @param array<string, float> $candidates  ähnliche, bereits gespeicherte Gesichter: faceLabel => Ähnlichkeit (0–100)
     * @param list<float>|null     $embedding   nur InsightFace: 512 Werte, L2-normalisiert
     * @param float                $confidence  Sicherheit der Detektion (0–100)
     * @param int|null             $sizePx      nur InsightFace: kürzere Seite der Box in Pixeln des analysierten Bildes
     */
    public function __construct(
        public string $faceId,
        public array $boundingBox,
        public float $confidence,
        public int $age,
        public string $gender,
        public ?string $emotion,
        public array $candidates,
        public ?array $embedding = null,
        public ?string $embeddingModel = null,
        public ?int $sizePx = null,
    ) {}
}
