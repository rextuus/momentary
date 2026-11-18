<?php

declare(strict_types=1);

namespace App\Service\Face\Embedding;

/**
 * Verteilt die Referenzen einer Person über die Aufnahmejahre (ReferenceSelector).
 *
 * Gruppiert nach Aufnahmejahr und reihum gefüllt: erst das beste Gesicht jedes Jahres, dann das zweitbeste usw. Jahre
 * nahe am Medium, das gerade analysiert wird, kommen in jeder Runde zuerst. Gesichter ohne Aufnahmezeit zählen neutral:
 * als eigene Gruppe in der Mitte der Reihenfolge. Bevorzugt wird nur über diese Auswahl; die Ähnlichkeit wird nicht
 * gewichtet.
 */
final class ReferenceYearSpread
{
    /**
     * Reihum über die Aufnahmejahre: in jeder Runde das nächstbeste Gesicht jedes Jahres, nahe Jahre zuerst.
     *
     * @param list<ReferenceCandidate> $faces
     *
     * @return list<ReferenceCandidate>
     */
    public static function pick(array $faces, int $limit, ?int $targetYear): array
    {
        /** @var array<int, list<ReferenceCandidate>> $byYear */
        $byYear = [];
        $unknown = [];
        foreach ($faces as $face) {
            if ($face->year === null) {
                $unknown[] = $face;
            } else {
                $byYear[$face->year][] = $face;
            }
        }

        $years = array_keys($byYear);
        usort($years, static fn(int $a, int $b): int => $targetYear === null
            ? $a <=> $b
            : [abs($a - $targetYear), $a] <=> [abs($b - $targetYear), $b]);
        $groups = array_map(static fn(int $year): array => $byYear[$year], $years);
        if ($unknown !== []) {
            array_splice($groups, intdiv(\count($groups), 2), 0, [$unknown]);
        }
        foreach ($groups as $i => $group) {
            usort($group, self::best(...));
            $groups[$i] = $group;
        }

        $selected = [];
        for ($round = 0; \count($selected) < $limit; $round++) {
            $added = false;
            foreach ($groups as $group) {
                if (isset($group[$round]) && \count($selected) < $limit) {
                    $selected[] = $group[$round];
                    $added = true;
                }
            }
            if (!$added) {
                break;
            }
        }

        return $selected;
    }

    private static function best(ReferenceCandidate $a, ReferenceCandidate $b): int
    {
        return (
            [$b->verified, $b->detectionScore ?? 0.0, $b->sizePx ?? 0, $b->faceId] <=> [
                $a->verified,
                $a->detectionScore ?? 0.0,
                $a->sizePx ?? 0,
                $a->faceId,
            ]
        );
    }
}
