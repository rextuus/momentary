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

    public function storeScenes(Video $video, array $scenes): void
    {
        $this->entityManager->persist($video); // Stelle sicher, dass das Video persistiert ist
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
}
