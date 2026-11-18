<?php

declare(strict_types=1);

namespace App\Service\Face\Embedding;

/**
 * Wählt je Person die Gesichter, gegen die neue Gesichter verglichen werden.
 *
 * Bei alten, verrauschten Videos streuen die Embeddings unsicherer und kleiner Gesichter stark. Referenz wird deshalb
 * nur, wer sicher genug erkannt und groß genug ist; verifizierte Gesichter zuerst, dann die sichersten. Hat eine Person
 * gar kein solches Gesicht, bleiben ihre besten wenigen Gesichter Referenz, sonst würde sie nie wiedererkannt.
 *
 * Menschen ändern sich über Jahrzehnte (Kinder wachsen auf); die Referenzen werden deshalb über die Aufnahmejahre
 * verteilt (ReferenceYearSpread).
 */
final class ReferenceSelector
{
    /** So viele Referenzen behält eine Person ohne ein einziges gutes Gesicht. */
    public const int FALLBACK_PER_PERSON = 3;

    /**
     * @param iterable<ReferenceCandidate> $candidates
     * @param float                        $minScore   Detektions-Score 0–100
     * @param int|null                     $targetYear Aufnahmejahr des analysierten Mediums; null = keine Vorliebe
     *
     * @return list<int> IDs der Referenzgesichter
     */
    public static function select(
        iterable $candidates,
        int $perPerson,
        float $minScore,
        int $minSizePx,
        ?int $targetYear = null,
    ): array {
        /** @var array<int, list<ReferenceCandidate>> $good */
        $good = [];
        /** @var array<int, list<ReferenceCandidate>> $other */
        $other = [];
        foreach ($candidates as $candidate) {
            $qualifies = ($candidate->detectionScore ?? 0.0) >= $minScore && ($candidate->sizePx ?? 0) >= $minSizePx;
            if ($qualifies) {
                $good[$candidate->personId][] = $candidate;
            } else {
                $other[$candidate->personId][] = $candidate;
            }
        }

        $selected = [];
        foreach ($good + $other as $personId => $_) {
            $faces = $good[$personId] ?? [];
            $limit = $perPerson;
            if ($faces === []) {
                $faces = $other[$personId] ?? [];
                $limit = min($perPerson, self::FALLBACK_PER_PERSON);
            }
            foreach (ReferenceYearSpread::pick($faces, $limit, $targetYear) as $face) {
                $selected[] = $face->faceId;
            }
        }

        return $selected;
    }
}
