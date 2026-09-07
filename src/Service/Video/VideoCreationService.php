<?php

namespace App\Service\Video;

use App\Entity\Tag;
use App\Entity\Video;
use App\Repository\VideoRepository;
use App\Service\Storage\FileManager;
use App\Service\Storage\FileStorageService;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Exception;

class VideoCreationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VideoRepository $videoRepository,
        private FileManager $fileManager,
        private FileStorageService $fileStorageService,
        private StoragePathProvider $pathProvider,
        private VideoProcessMessageDispatcher $videoProcessMessageDispatcher
    ) {}

    /**
     * @throws Exception
     */
    public function createVideo(Video $video, string $sourceFilename, ?Tag $cameraTag, ?Tag $formatTag): Video
    {
        $absoluteImportPath = $this->pathProvider->getImportAbsolutePath($sourceFilename);

        if (!file_exists($absoluteImportPath)) {
            throw new Exception(sprintf('Die Datei "%s" wurde im Import-Storage nicht gefunden.', $sourceFilename));
        }

        if ($this->videoRepository->findOneBy(['title' => $video->getTitle()])) {
            throw new Exception('Ein Video mit diesem Titel existiert bereits.');
        }

        $video->setCreatedAt(new \DateTimeImmutable());

        if ($cameraTag) {
            $video->addTag($cameraTag);
        }
        if ($formatTag) {
            $video->addTag($formatTag);
        }

        $importRelativePath = $this->pathProvider->getImportRelativePath($sourceFilename);

        $this->entityManager->getConnection()->beginTransaction();
        try {
            // 1. Video persistieren, damit es eine ID erhält
            $this->entityManager->persist($video);
            $this->entityManager->flush();

            // 2. File-Entity über den FileManager erzeugen (nutzt die echte Video-ID für den Pfad)
            $file = $this->fileManager->createVideoSourceFile($video, $sourceFilename);
            $this->entityManager->persist($file);

            // 3. Physisch vom Import-Ordner in den zielbezogenen Video-Pfad verschieben
            $finalRelativePath = $file->getRelativePath();
            $this->fileStorageService->moveFile($importRelativePath, $finalRelativePath);

            // 4. Verknüpfen und speichern
            $video->setSourceFile($file);
            $this->entityManager->persist($video);
            $this->entityManager->flush();

            $this->entityManager->getConnection()->commit();
        } catch (Exception $e) {
            $this->entityManager->getConnection()->rollBack();
            throw $e;
        }

        $this->videoProcessMessageDispatcher->dispatchInitialProcessMessage($video);

        return $video;
    }
}
