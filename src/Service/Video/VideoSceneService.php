<?php

declare(strict_types=1);

namespace App\Service\Video;

use App\Entity\Video;
use App\Entity\VideoScene;
use Doctrine\ORM\EntityManagerInterface;

readonly class VideoSceneService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /** Liegt ein optischer Schnitt so nah an einer Aufnahmegrenze, gilt er als dieselbe Stelle */
    public const float BOUNDARY_SNAP_SECONDS = 0.5;

    /**
     * Szenen speichern. Grenzen zwischen Aufnahmen (RecordingSegment) sind sichere Schnitte: Wo die optische
     * Erkennung dort keinen gefunden hat, wird die Szene geteilt. Video::sceneCuts bleibt die rohe optische Liste.
     *
     * @param list<array<string, mixed>> $scenes Szenen wie von PySceneDetect geliefert
     */
    public function storeScenes(Video $video, array $scenes): void
    {
        $this->entityManager->persist($video); // Stelle sicher, dass das Video persistiert ist
        $video->setSceneCuts(self::cuts($scenes));
        $ranges = array_map(static fn(array $data): array => [
            (float) $data['start_seconds'],
            (float) $data['end_seconds'],
        ], $scenes);
        foreach (self::splitAtBoundaries($ranges, $video->getRecordingBoundaries()) as $number => [$start, $end]) {
            $scene = new VideoScene();
            $scene->setSceneNumber($number + 1);
            $scene->setStartSeconds($start);
            $scene->setEndSeconds($end);
            $video->addScene($scene);
            $this->entityManager->persist($scene);
        }

        $this->entityManager->flush();
    }

    /**
     * @param list<array{float, float}> $ranges     Szenen [Start, Ende], aufsteigend
     * @param list<float>               $boundaries Aufnahmegrenzen
     *
     * @return list<array{float, float}>
     */
    public static function splitAtBoundaries(array $ranges, array $boundaries): array
    {
        foreach ($boundaries as $boundary) {
            $split = [];
            foreach ($ranges as [$start, $end]) {
                $inside =
                    ($boundary - $start) > self::BOUNDARY_SNAP_SECONDS
                    && ($end - $boundary) > self::BOUNDARY_SNAP_SECONDS;
                if ($inside) {
                    $split[] = [$start, $boundary];
                    $split[] = [$boundary, $end];
                    continue;
                }
                $split[] = [$start, $end];
            }
            $ranges = $split;
        }

        return $ranges;
    }

    /**
     * Rohe Schnitte: Beginn jeder Szene außer der ersten, aufsteigend, auf Millisekunden gerundet.
     *
     * @param list<array<string, mixed>> $scenes
     *
     * @return list<float>
     */
    public static function cuts(array $scenes): array
    {
        $cuts = [];
        foreach ($scenes as $data) {
            $start = round((float) ($data['start_seconds'] ?? 0.0), 3);
            if ($start > 0.0) {
                $cuts[] = $start;
            }
        }
        sort($cuts);

        return array_values(array_unique($cuts, \SORT_REGULAR));
    }
}
