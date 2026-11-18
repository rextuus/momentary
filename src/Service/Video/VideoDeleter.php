<?php

declare(strict_types=1);

namespace App\Service\Video;

use App\Entity\Video;
use App\Service\Face\FaceForgetter;
use App\Service\Face\OrphanedPersonCleaner;
use App\Service\Storage\FileManager;
use App\Service\Storage\FileStorageService;
use App\Service\Storage\StoragePathProvider;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Löscht ein Video vollständig: Dateien (Video, Frames, Gesichtsbilder), Datenbank (Szenen, Gesichter per Cascade),
 * Gesichter beim Anbieter, dadurch leere automatische Personen; den Sucheintrag entfernt
 * VideoSearchIndexRemovalListener.
 *
 * Läuft noch eine Gesichtserkennung für das Video, merkt der Worker das (DeletedMediumGuard) und bricht ab, ohne
 * etwas zurückzulassen. Fehler beim Anbieter (z. B. abgelaufener AWS-Zugang) blockieren das Löschen nie.
 */
class VideoDeleter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileManager $fileManager,
        private readonly FileStorageService $fileStorage,
        private readonly StoragePathProvider $pathProvider,
        private readonly OrphanedPersonCleaner $orphanedPersonCleaner,
        private readonly FaceForgetter $faceForgetter,
    ) {}

    public function delete(Video $video): void
    {
        $videoId = (int) $video->getId();
        $affectedPersons = $this->orphanedPersonCleaner->personIdsOfMedium($videoId);
        // Vor dem Löschen einsammeln: danach sind die Gesichter weg
        $labels = FaceForgetter::labelsByProvider($video->getFaces());

        foreach ([$video->getSourceFile(), $video->getConvertedFile(), $video->getThumbnailFile()] as $file) {
            if ($file !== null) {
                $this->fileManager->deleteFile($file, false);
            }
        }
        foreach ($video->getEvents()->toArray() as $event) {
            $event->removeMedium($video);
        }

        // Zeile zuerst: ein laufender Frame-Job sieht dann, dass das Video weg ist, bevor sein Ordner verschwindet
        $this->entityManager->remove($video);
        $this->entityManager->flush();

        $this->fileStorage->deleteDirectory('videos/' . $videoId);
        $this->fileStorage->deleteDirectory('frames/' . $videoId);
        // Gesichtsbilder (ganze Frames) unter images/faces/<videoId>
        $this->fileStorage->deleteDirectory(\dirname($this->pathProvider->createFacePath((string) $videoId, 'x.jpg')));

        // Keine veralteten Einträge in der Identity Map, bevor leere Personen gesucht werden
        $this->entityManager->clear();
        $this->orphanedPersonCleaner->removeOrphans($affectedPersons);

        $this->faceForgetter->forget($labels, sprintf('Video %d', $videoId));
    }
}
