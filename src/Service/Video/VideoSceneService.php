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

    /**
     * @param list<array<string, mixed>> $scenes Szenen wie von PySceneDetect geliefert
     */
    public function storeScenes(Video $video, array $scenes): void
    {
        $this->entityManager->persist($video); // Stelle sicher, dass das Video persistiert ist
        $video->setSceneCuts(self::cuts($scenes));
        foreach ($scenes as $data) {
            $scene = new VideoScene();
            $scene->setSceneNumber((int) $data['scene_number']);
            $scene->setStartSeconds((float) $data['start_seconds']);
            $scene->setEndSeconds((float) $data['end_seconds']);
            $video->addScene($scene);
            $this->entityManager->persist($scene);
        }

        $this->entityManager->flush();
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
