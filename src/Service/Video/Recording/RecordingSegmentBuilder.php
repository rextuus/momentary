<?php

declare(strict_types=1);

namespace App\Service\Video\Recording;

use App\Entity\RecordingSegment;
use App\Service\Media\CameraClock;

/**
 * Macht aus den Läufen von read_dv_subcodes.py Aufnahme-Segmente (documentation/dv_aufnahmedatum.md, 2, 6, 7):
 *
 * - Ungültige Kamerazeiten (vor CameraClock::EARLIEST_PLAUSIBLE, mehr als einen Tag in der Zukunft) zählen wie
 *   fehlende; aneinandergrenzende Bereiche ohne Datum werden eins.
 * - Läufe unter MIN_SECONDS (Bandfehler, einzelne Frames mit kaputtem Subcode) gehen im vorigen Segment auf.
 * - Ein Lauf, der zeitlich nahtlos an den vorigen anschließt und keinen Aufnahmebeginn trägt, setzt ihn fort.
 * - Werksdatum nach leerem Pufferakku (1. Januar, erste Stunde) wird als verdächtig markiert.
 *
 * Eine Datei ganz ohne gültiges Datum ergibt ein Segment ohne Datum über das ganze Video.
 */
final class RecordingSegmentBuilder
{
    public const float MIN_SECONDS = 1.0;

    /**
     * @return list<RecordingSegment>
     */
    public static function build(DvSubcodes $subcodes, ?\DateTimeImmutable $now = null): array
    {
        if ($subcodes->runs === [] || $subcodes->fps <= 0.0) {
            return [];
        }
        $latest = ($now ?? new \DateTimeImmutable())->modify('+1 day');
        $earliest = new \DateTimeImmutable(
            CameraClock::EARLIEST_PLAUSIBLE,
            new \DateTimeZone(DvSubcodeReader::CAMERA_TIMEZONE),
        );

        $ranges = [];
        foreach ($subcodes->runs as $run) {
            $time = $run->cameraTime;
            $plausible = $time !== null && $time >= $earliest && $time <= $latest;
            $ranges[] = new RecordingRange(
                $run->startFrame / $subcodes->fps,
                ($run->endFrame + 1) / $subcodes->fps,
                $plausible ? $time : null,
                $run->recStart,
            );
        }

        $merged = [];
        foreach (self::absorbShortRanges($ranges) as $range) {
            $last = array_key_last($merged);
            if ($last !== null && $merged[$last]->isContinuedBy($range)) {
                $merged[$last] = $merged[$last]->withEnd($range->end);
                continue;
            }
            $merged[] = $range;
        }

        return array_map(static function (RecordingRange $range): RecordingSegment {
            $segment = new RecordingSegment(
                round($range->start, 3),
                round($range->end, 3),
                $range->time?->setTimezone(new \DateTimeZone('UTC')),
            );

            return $segment->setSuspicious($range->time !== null && FactoryDate::matches($range->time));
        }, $merged);
    }

    /**
     * Zu kurze Bereiche gehen im vorigen auf (am Anfang im nächsten); nur kurze ergeben einen über alles.
     *
     * @param list<RecordingRange> $ranges
     *
     * @return list<RecordingRange>
     */
    private static function absorbShortRanges(array $ranges): array
    {
        $result = [];
        $carryStart = null;
        foreach ($ranges as $range) {
            $last = array_key_last($result);
            if ($range->duration() >= self::MIN_SECONDS) {
                $result[] = $carryStart === null ? $range : $range->withStart($carryStart);
                $carryStart = null;
            } elseif ($last !== null) {
                $result[$last] = $result[$last]->withEnd($range->end);
            } else {
                $carryStart ??= $range->start;
            }
        }

        if ($result === [] && $ranges !== []) {
            $result[] = $ranges[0]->withEnd($ranges[array_key_last($ranges)]->end);
        }

        return $result;
    }
}
