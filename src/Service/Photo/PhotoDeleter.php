<?php

declare(strict_types=1);

namespace App\Service\Photo;

use App\Entity\Photo;
use App\Service\Aws\AmazonRekognitionService;
use App\Service\Face\OrphanedPersonCleaner;
use App\Service\Storage\FileStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Löscht ein Foto vollständig: Dateien, Gesichter (auch in AWS), dadurch leere Personen und den Sucheintrag.
 */
class PhotoDeleter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileStorageService $fileStorage,
        private readonly AmazonRekognitionService $rekognition,
        private readonly OrphanedPersonCleaner $orphanedPersonCleaner,
        private readonly PhotoSearchIndex $searchIndex,
        private readonly LoggerInterface $logger,
    ) {}

    public function delete(Photo $photo): void
    {
        $photoId = (int) $photo->getId();
        $personIds = $this->orphanedPersonCleaner->personIdsOfMedium($photoId);
        $labels = array_values(array_filter(array_map(
            static fn($face): ?string => $face->getFaceLabel(),
            $photo->getFaces()->toArray(),
        )));

        foreach ($photo->getFaces()->toArray() as $face) {
            // Das Foto ist zugleich Bilddatei der Gesichter; die löscht das Foto selbst
            $face->setFaceImage(null);
            $face->getPerson()?->removeVideoFace($face);
        }
        foreach ($photo->getEvents()->toArray() as $event) {
            $event->removeMedium($photo);
        }
        $this->entityManager->remove($photo);
        $this->entityManager->flush();

        $this->fileStorage->deleteDirectory('photos/' . $photoId);
        $this->orphanedPersonCleaner->removeOrphans($personIds);

        try {
            $this->rekognition->deleteFaces($labels);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('AWS-Gesichter von Foto %d: %s', $photoId, $e->getMessage()));
        }
        try {
            $this->searchIndex->remove($photoId);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Suchindex für Foto %d: %s', $photoId, $e->getMessage()));
        }
    }
}
