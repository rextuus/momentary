<?php

declare(strict_types=1);

namespace App\Service\Face;

use App\Entity\Person;
use App\Enum\PersonStatus;
use App\Repository\VideoFaceRepository;

/**
 * Ordnet ein neu erkanntes Gesicht anhand seiner Kandidaten (Rekognition oder InsightFace) einer vorhandenen Person zu.
 */
class FaceMatcher
{
    /** Unterhalb dieser Ähnlichkeit gilt ein abweichendes Geschlecht als Ausschluss. */
    private const float GENDER_OVERRIDE_SIMILARITY = 95.0;

    public function __construct(
        private readonly VideoFaceRepository $videoFaceRepository,
    ) {}

    /**
     * Bester Kandidat je Person, absteigend nach Ähnlichkeit.
     *
     * @param list<int> $excludedPersonIds Personen, die schon im selben Frame vorkommen
     * @param bool      $genderCheck       abweichendes Geschlecht schließt unsichere Treffer aus (bei InsightFace
     *                                     abschaltbar, weil dessen Schätzung unzuverlässiger ist)
     *
     * @return list<FaceMatch>
     */
    public function rankPersons(DetectedFace $face, array $excludedPersonIds = [], bool $genderCheck = true): array
    {
        $known = $this->videoFaceRepository->findByFaceLabels(array_keys($face->candidates));

        /** @var array<int, FaceMatch> $bestPerPerson */
        $bestPerPerson = [];
        foreach ($face->candidates as $label => $similarity) {
            $candidateFace = $known[$label] ?? null;
            $person = $candidateFace?->getPerson();
            if ($candidateFace === null || $person === null) {
                continue;
            }

            $person = self::resolveMerged($person);
            $personId = $person->getId();
            if ($personId === null || \in_array($personId, $excludedPersonIds, true)) {
                continue;
            }

            if (
                $genderCheck
                && $similarity < self::GENDER_OVERRIDE_SIMILARITY
                && self::gendersConflict($face->gender, $candidateFace->getGender())
            ) {
                continue;
            }

            if (!isset($bestPerPerson[$personId]) || $bestPerPerson[$personId]->similarity < $similarity) {
                $bestPerPerson[$personId] = new FaceMatch($person, $candidateFace, $similarity);
            }
        }

        $ranked = array_values($bestPerPerson);
        usort($ranked, static fn(FaceMatch $a, FaceMatch $b): int => $b->similarity <=> $a->similarity);

        return $ranked;
    }

    /**
     * Zusammengeführte Personen leiten auf ihr Ziel weiter.
     */
    public static function resolveMerged(Person $person): Person
    {
        $seen = [];
        while (
            $person->getStatus() === PersonStatus::MERGED
            && $person->getMergedInto() !== null
            && !isset($seen[spl_object_id($person)])
        ) {
            $seen[spl_object_id($person)] = true;
            $person = $person->getMergedInto();
        }

        return $person;
    }

    public static function gendersConflict(?string $a, ?string $b): bool
    {
        $known = ['Male', 'Female'];

        return \in_array($a, $known, true) && \in_array($b, $known, true) && $a !== $b;
    }
}
