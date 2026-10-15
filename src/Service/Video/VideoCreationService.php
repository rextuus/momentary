<?php

namespace App\Service\Video;

use App\Entity\Tag;
use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Service\Storage\FileManager;
use App\Service\Storage\FileStorageService;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Queue\ImportQueueManager;
use App\Service\Video\VideoNamingService;
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
        private VideoProcessMessageDispatcher $videoProcessMessageDispatcher,
        private VideoNamingService $videoNamingService,
        private ImportQueueManager $importQueueManager,
        private \Psr\Log\LoggerInterface $logger
    ) {}

    /**
     * @throws Exception
     */
    public function createVideo(Video $video, string $sourceFilename, ?Tag $cameraTag, ?Tag $formatTag, bool $autoProcess = false): Video
    {
        error_log('VideoCreationService: createVideo called for ' . $sourceFilename . ' with autoProcess = ' . ($autoProcess ? 'true' : 'false'));
        $absoluteImportPath = $this->pathProvider->getImportAbsolutePath($sourceFilename);

        if (!file_exists($absoluteImportPath)) {
            throw new Exception(sprintf('Die Datei "%s" wurde im Import-Storage nicht gefunden.', $sourceFilename));
        }

        if ($autoProcess && empty($video->getTitle())) {
            $video->setTitle($this->videoNamingService->suggestName($video));
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
        $this->logger->info('VideoCreationService: Transaction started.');
        try {
            // 1. Video persistieren, damit es eine ID erhält
            $this->entityManager->persist($video);
            $this->entityManager->flush();
            $this->logger->info('VideoCreationService: Video persisted with ID ' . $video->getId());

            // 2. File-Entity über den FileManager erzeugen (nutzt die echte Video-ID für den Pfad)
            $file = $this->fileManager->createVideoSourceFile($video, $sourceFilename);
            $this->entityManager->persist($file);
            $this->entityManager->flush();
            $this->logger->info('VideoCreationService: File entity persisted.');

            // 3. Physisch vom Import-Ordner in den zielbezogenen Video-Pfad verschieben
            $finalRelativePath = $file->getRelativePath();
            $this->fileStorageService->moveFile($importRelativePath, $finalRelativePath);
            $this->logger->info('VideoCreationService: File moved to ' . $finalRelativePath);

            // 4. Verknüpfen und speichern
            $video->setSourceFile($file);
            $this->entityManager->persist($video);
            $this->entityManager->flush();
            $this->logger->info('VideoCreationService: Video finalized.');

            $this->entityManager->getConnection()->commit();
            $this->logger->info('VideoCreationService: Transaction committed.');
        } catch (Exception $e) {
            $this->entityManager->getConnection()->rollBack();
            $this->logger->error('VideoCreationService: Transaction failed: ' . $e->getMessage());
            $this->logger->error('VideoCreationService: Stack trace: ' . $e->getTraceAsString());
            throw $e;
        }

        if ($autoProcess) {
            $this->logger->info('VideoCreationService: Auto-processing video ' . $video->getId());
            $video->setStatus(VideoStatus::QUEUED);
            $this->entityManager->flush();
            $this->importQueueManager->processQueue();
        }

        return $video;
    }
}
