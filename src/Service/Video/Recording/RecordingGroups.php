<?php

declare(strict_types=1);

namespace App\Service\Video\Recording;

use App\Entity\VideoScene;

/**
 * Szenen nach Aufnahme gruppieren (Video::getRecordingBoundaries): Zusammenlegen und Kapitel reichen nie über eine
 * Aufnahmegrenze.
 */
final class RecordingGroups
{
    /**
     * Aufeinanderfolgende Szenen derselben Aufnahme (zugeordnet über die Szenenmitte).
     *
     * @param list<VideoScene> $scenes
     * @param list<float>      $boundaries
     *
     * @return list<list<VideoScene>>
     */
    public static function scenes(array $scenes, array $boundaries): array
    {
        $groups = [];
        $current = null;
        foreach ($scenes as $scene) {
            $recording = self::indexAt(($scene->getStartSeconds() + $scene->getEndSeconds()) / 2, $boundaries);
            if ($recording !== $current) {
                $groups[] = [];
                $current = $recording;
            }
            $groups[array_key_last($groups)][] = $scene;
        }

        return $groups;
    }

    /**
     * @param list<float> $boundaries
     */
    public static function indexAt(float $seconds, array $boundaries): int
    {
        return \count(array_filter($boundaries, static fn(float $boundary): bool => $boundary <= $seconds));
    }
}
