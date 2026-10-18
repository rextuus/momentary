<?php

declare(strict_types=1);

namespace App\Service\Person;

use App\Entity\Person;
use App\Entity\VideoFace;

/**
 * Zwei Personen, deren Gesichter sich laut Rekognition ähneln: vermutlich derselbe Mensch.
 */
final readonly class PersonPairSuggestion
{
    public function __construct(
        /** bleibt beim Zusammenführen erhalten (benannt bzw. mit mehr Gesichtern) */
        public Person $keep,
        public Person $merge,
        public float $similarity,
        /** Zahl der Gesichtspaare, die die Ähnlichkeit stützen */
        public int $support,
        public ?VideoFace $keepFace,
        public ?VideoFace $mergeFace,
    ) {}

    public function getConfidence(): string
    {
        return match (true) {
            $this->similarity >= 90 => 'sehr wahrscheinlich',
            $this->similarity >= 80 => 'wahrscheinlich',
            default => 'möglich',
        };
    }

    public function getConfidenceColor(): string
    {
        return match (true) {
            $this->similarity >= 90 => 'success',
            $this->similarity >= 80 => 'primary',
            default => 'secondary',
        };
    }
}
