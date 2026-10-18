<?php

declare(strict_types=1);

namespace App\Service\Face;

use App\Entity\Person;
use App\Entity\VideoFace;

/**
 * Bester Treffer für ein neu erkanntes Gesicht.
 */
final readonly class FaceMatch
{
    public function __construct(
        public Person $person,
        public VideoFace $face,
        public float $similarity,
    ) {}
}
