<?php

declare(strict_types=1);

namespace App\Service\Photo;

use App\Entity\Photo;
use App\Service\Face\FaceForgetter;
use App\Service\Face\OrphanedPersonCleaner;
use App\Service\Storage\FileStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Löscht ein Foto vollständig: Dateien, Gesichter (auch beim Anbieter, z. B. AWS), dadurch leere Personen und den
 * Sucheintrag.
 */
class PhotoDeleter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileStorageService $fileStorage,
        private readonly FaceForgetter $faceForgetter,
        private readonly OrphanedPersonCleaner $orphanedPersonCleaner,
        private readonly PhotoSearchIndex $searchIndex,
        private readonly PhotoBursts $bursts,
        private readonly LoggerInterface $logger,
    ) {}

    public function delete(Photo $photo): void
    {
        $photoId = (int) $photo->getId();
        $personIds = $this->orphanedPersonCleaner->personIdsOfMedium($photoId);
        $labels = FaceForgetter::labelsByProvider($photo->getFaces());

        foreach ($photo->getFaces()->toArray() as $face) {
            // Das Foto ist zugleich Bilddatei der Gesichter; die löscht das Foto selbst
            $face->setFaceImage(null);
            $face->getPerson()?->removeVideoFace($face);
        }
        foreach ($photo->getEvents()->toArray() as $event) {
            $event->removeMedium($photo);
        }
        // Serie ohne dieses Foto neu bilden (bestes Foto ggf. neu wählen)
        $owner = $photo->getOwner();
        $takenAt = $photo->getTakenAt();
        foreach ($photo->getBurstMembers()->toArray() as $member) {
            $member->setBurstCover(null);
        }
        $photo->setBurstCover(null);
        $this->entityManager->remove($photo);
        $this->entityManager->flush();

        if ($takenAt !== null) {
            $this->bursts->regroupWindow($owner, $takenAt);
        }

        $this->fileStorage->deleteDirectory('photos/' . $photoId);
        $this->orphanedPersonCleaner->removeOrphans($personIds);

        $this->faceForgetter->forget($labels, sprintf('Foto %d', $photoId));
        try {
            $this->searchIndex->remove($photoId);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Suchindex für Foto %d: %s', $photoId, $e->getMessage()));
        }
    }
}
