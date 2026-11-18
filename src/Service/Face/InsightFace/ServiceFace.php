<?php

declare(strict_types=1);

namespace App\Service\Face\InsightFace;

/**
 * Ein vom face-service gefundenes Gesicht. Embedding, Alter und Geschlecht gibt es nur bei /analyze.
 */
final readonly class ServiceFace
{
    /**
     * @param array{float, float, float, float} $box       x1, y1, x2, y2 in Pixeln des Bildes
     * @param list<array{float, float}>         $landmarks Augen, Nase, Mundwinkel
     * @param list<float>|null                  $embedding 512 Werte, L2-normalisiert
     * @param 'M'|'F'|null                      $gender
     */
    public function __construct(
        public array $box,
        public float $score,
        public array $landmarks = [],
        public ?array $embedding = null,
        public ?int $age = null,
        public ?string $gender = null,
    ) {}

    /**
     * Kürzere Seite der Box in Pixeln.
     */
    public function shortSide(): float
    {
        return min($this->box[2] - $this->box[0], $this->box[3] - $this->box[1]);
    }

    /**
     * Box relativ zur Bildgröße im Format, das VideoFace (aus Rekognition-Zeiten) speichert.
     *
     * @return array{Left: float, Top: float, Width: float, Height: float}
     */
    public function relativeBox(int $width, int $height): array
    {
        $clamp = static fn(float $value): float => max(0.0, min(1.0, $value));
        $left = $clamp($this->box[0] / $width);
        $top = $clamp($this->box[1] / $height);

        return [
            'Left' => $left,
            'Top' => $top,
            'Width' => $clamp($this->box[2] / $width) - $left,
            'Height' => $clamp($this->box[3] / $height) - $top,
        ];
    }

    /**
     * Geschlecht in der Schreibweise, die VideoFace und FaceMatcher kennen.
     */
    public function genderLabel(): string
    {
        return match ($this->gender) {
            'M' => 'Male',
            'F' => 'Female',
            default => 'Unknown',
        };
    }
}
