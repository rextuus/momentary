<?php

declare(strict_types=1);

namespace App\Service\Face\Clustering;

/**
 * Ein Gesicht eines Videos, reduziert auf das, was die Gruppierung braucht.
 */
final readonly class FaceNode
{
    /**
     * @param string               $frame       Kennung des Frames; Gesichter desselben Frames sind nie dieselbe Person
     * @param array<string, float> $candidates  Rekognition-FaceId => Ähnlichkeit
     * @param array<string, float> $boundingBox relative Box (Left/Top/Width/Height)
     * @param int|null             $anchor      feste Person (vom Nutzer bestätigt); null = frei zuordenbar
     */
    public function __construct(
        public int $id,
        public string $frame,
        public ?string $label,
        public array $candidates,
        public ?int $sceneId,
        public int $timestamp,
        public array $boundingBox,
        public ?int $anchor = null,
    ) {}
}
