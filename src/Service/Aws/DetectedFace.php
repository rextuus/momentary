<?php

declare(strict_types=1);

namespace App\Service\Aws;

/**
 * Ein in einem Frame erkanntes und in der Rekognition-Collection indiziertes Gesicht.
 */
final readonly class DetectedFace
{
    /**
     * @param array<string, float> $boundingBox relative Box mit Left/Top/Width/Height
     * @param array<string, float> $candidates  ähnliche, bereits indizierte Gesichter: Rekognition-FaceId => Ähnlichkeit
     */
    public function __construct(
        public string $faceId,
        public array $boundingBox,
        public float $confidence,
        public int $age,
        public string $gender,
        public string $emotion,
        public array $candidates,
    ) {}
}
