<?php

declare(strict_types=1);

namespace App\Service\Face;

use App\Entity\VideoFace;
use App\Service\Face\Clustering\FaceClusterer;

/**
 * Zuordnung gemerkter bestätigter Gesichter zu neu erkannten (VerifiedFaceCarryover). Rechnet nur, speichert nichts.
 *
 * Ein Paar zählt, wenn der Zeitabstand höchstens die Toleranz beträgt und die Boxen mindestens MIN_IOU überlappen.
 * Zeitlich nächste Paare gewinnen, danach die größte Überlappung. Jedes gemerkte und jedes neue Gesicht kommt höchstens
 * einmal vor, und eine Person höchstens einmal je Frame.
 */
final class CarryoverMatcher
{
    public const float MIN_IOU = 0.5;

    /**
     * @param list<array{timestamp: int, box: array<string, float>, person: int}> $carryover
     * @param list<VideoFace>                                                      $faces
     *
     * @return list<array{face: VideoFace, person: int, entry: int}> entry = Index in $carryover
     */
    public static function match(array $carryover, array $faces, float $toleranceSeconds): array
    {
        $usedOld = [];
        $usedNew = [];
        $personsInFrame = [];
        $matches = [];
        foreach (self::candidatePairs($carryover, $faces, $toleranceSeconds) as [$old, $new]) {
            $personId = $carryover[$old]['person'];
            $frame = self::frameKey($faces[$new]);
            if (isset($usedOld[$old]) || isset($usedNew[$new]) || isset($personsInFrame[$frame][$personId])) {
                continue;
            }
            $usedOld[$old] = true;
            $usedNew[$new] = true;
            $personsInFrame[$frame][$personId] = true;
            $matches[] = ['face' => $faces[$new], 'person' => $personId, 'entry' => $old];
        }

        return $matches;
    }

    /** Wie VideoFaceConsolidator: alle Gesichter eines Frames teilen sich dasselbe Frame-Bild. */
    public static function frameKey(VideoFace $face): string
    {
        return $face->getFaceImage()?->getId() !== null
            ? 'file:' . $face->getFaceImage()->getId()
            : 'ts:' . $face->getTimestamp();
    }

    /**
     * @param list<array{timestamp: int, box: array<string, float>, person: int}> $carryover
     * @param list<VideoFace>                                                      $faces
     *
     * @return list<array{int, int}> [gemerkt, neu], beste zuerst
     */
    private static function candidatePairs(array $carryover, array $faces, float $toleranceSeconds): array
    {
        $pairs = [];
        foreach ($carryover as $old => $entry) {
            foreach ($faces as $new => $face) {
                $box = $face->getBoundingBox();
                $gap = abs((int) $face->getTimestamp() - $entry['timestamp']);
                if ($box === null || $gap > $toleranceSeconds) {
                    continue;
                }
                $iou = FaceClusterer::iou($entry['box'], array_map(floatval(...), $box));
                if ($iou >= self::MIN_IOU) {
                    $pairs[] = ['pair' => [$old, $new], 'rank' => [$gap, -$iou]];
                }
            }
        }
        usort($pairs, static fn(array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return array_column($pairs, 'pair');
    }
}
