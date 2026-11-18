<?php

declare(strict_types=1);

namespace App\Service\Face;

use App\Entity\Video;
use App\Service\Storage\FileStorageService;
use App\Service\Storage\StoragePathProvider;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Entfernt alle Gesichter eines Videos, um es neu zu erkennen: Gesichter samt Gesichtsbildern (images/faces/<id>),
 * beim Anbieter vergessen (FaceForgetter), danach leere automatische Personen aufräumen.
 */
class VideoFaceRemover
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StoragePathProvider $pathProvider,
        private readonly FileStorageService $fileStorage,
        private readonly OrphanedPersonCleaner $orphanedPersonCleaner,
        private readonly FaceForgetter $faceForgetter,
    ) {}

    public function removeAll(Video $video): void
    {
        $videoId = (int) $video->getId();
        $personIds = $this->orphanedPersonCleaner->personIdsOfMedium($videoId);
        $labels = FaceForgetter::labelsByProvider($video->getFaces());
        foreach ($video->getFaces()->toArray() as $face) {
            $face->getPerson()?->removeVideoFace($face);
            $face->getVideoScene()?->removeVideoFace($face);
            $video->removeFace($face);
            $this->entityManager->remove($face);
        }
        $this->entityManager->flush();

        $this->fileStorage->deleteDirectory(\dirname($this->pathProvider->createFacePath((string) $videoId, 'x.jpg')));
        $this->faceForgetter->forget($labels, sprintf('Video %d', $videoId));
        $this->orphanedPersonCleaner->removeOrphans($personIds);
    }
}
