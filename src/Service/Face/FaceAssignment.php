<?php

declare(strict_types=1);

namespace App\Service\Face;

use App\Entity\Person;

/**
 * Ergebnis des Abgleichs eines neuen Gesichts (FaceAssigner).
 */
final readonly class FaceAssignment
{
    /**
     * @param Person|null    $person     vorhandene Person ab der Zuordnungsschwelle; null = neue Person anlegen
     * @param FaceMatch|null $best       bester Treffer überhaupt (auch unter der Schwelle)
     * @param Person|null    $suggestion beste bereits benannte Person ab der Vorschlagsschwelle
     */
    public function __construct(
        public ?Person $person,
        public ?FaceMatch $best,
        public ?Person $suggestion,
    ) {}
}
