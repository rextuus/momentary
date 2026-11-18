<?php

declare(strict_types=1);

namespace App\Service\Face;

use App\Enum\PersonStatus;

/**
 * Abgleich: Zu welcher vorhandenen Person gehört ein neu erkanntes Gesicht, und wen schlagen wir vor?
 * Speichert nichts; das übernimmt FaceRecorder.
 */
class FaceAssigner
{
    public function __construct(
        private readonly FaceMatcher $faceMatcher,
    ) {}

    /**
     * @param list<int> $personIdsInFrame Personen, die im selben Bild schon vergeben sind (nie dieselbe Person)
     */
    public function assign(DetectedFace $face, array $personIdsInFrame, FaceThresholds $thresholds): FaceAssignment
    {
        $ranked = $this->faceMatcher->rankPersons($face, $personIdsInFrame, $thresholds->genderCheck);
        $best = $ranked[0] ?? null;

        // Vorschlag: beste bereits identifizierte Person, auch wenn sie nicht automatisch zugeordnet wird
        $suggestion = null;
        foreach ($ranked as $match) {
            if ($match->similarity < $thresholds->suggestion) {
                break;
            }
            if ($match->person->getStatus() === PersonStatus::IDENTIFIED) {
                $suggestion = $match->person;
                break;
            }
        }

        return new FaceAssignment(
            person: $best !== null && $best->similarity >= $thresholds->match ? $best->person : null,
            best: $best,
            suggestion: $suggestion,
        );
    }
}
