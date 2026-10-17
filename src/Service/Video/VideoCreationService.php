<?php

declare(strict_types=1);

namespace App\Service\Video;

use App\Entity\Tag;
use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Service\Storage\FileManager;
use App\Service\Storage\FileStorageService;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Queue\ImportQueueManager;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class VideoCreationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VideoRepository $videoRepository,
        private FileManager $fileManager,
        private FileStorageService $fileStorageService,
        private StoragePathProvider $pathProvider,
        private VideoNamingService $videoNamingService,
        private ImportQueueManager $importQueueManager,
        private SluggerInterface $slugger,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws Exception
     */
    public function createVideo(
        Video $video,
        UploadedFile $uploadedFile,
        ?Tag $cameraTag,
        ?Tag $formatTag,
        bool $autoProcess = false,
    ): Video {
        $this->logger->info('VideoCreationService: createVideo called with direct uploaded file.');

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

        $this->entityManager->getConnection()->beginTransaction();
        try {
            // 1. Video persistieren, um die echte DB-ID zu erhalten
            $this->entityManager->persist($video);
            $this->entityManager->flush();
            $this->entityManager->refresh($video);

            $videoId = $video->getId();
            $directoryIdentifier = (string) $videoId;

            // 2. Zielverzeichnis erstellen (videos/{id}/)
            $targetRelativeDir = 'videos/' . $directoryIdentifier;
            $this->fileStorageService->createDirectoryStructure($targetRelativeDir);

            // 3. Absolut eindeutigen Dateinamen generieren (verhindert uniqid-Kollisionen)
            $originalFilename = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $this->slugger->slug($originalFilename);
            $extension = $uploadedFile->guessExtension() ?? $uploadedFile->getClientOriginalExtension();
            $newFilename = sprintf('%s-%s.%s', $safeFilename, bin2hex(random_bytes(6)), $extension);

            $targetDirectoryAbsolutePath = $this->pathProvider->getStorageRoot() . '/' . $targetRelativeDir;

            // 4. Datei direkt in den finalen Zielordner verschieben
            $uploadedFile->move($targetDirectoryAbsolutePath, $newFilename);

            // Synchronisation erzwingen
            $absoluteTargetPath = $targetDirectoryAbsolutePath . '/' . $newFilename;
            if (file_exists($absoluteTargetPath)) {
                clearstatcache(true, $absoluteTargetPath);
            }

            // 5. File-Entity über deinen FileManager erzeugen und Pfad zuweisen
            $file = $this->fileManager->createVideoSourceFile($video, $newFilename);
            $file->setRelativePath($targetRelativeDir . '/' . $newFilename);
            $file->setFileSize(file_exists($absoluteTargetPath) ? (filesize($absoluteTargetPath) ?: 0) : 0);

            $this->entityManager->persist($file);
            $this->entityManager->flush();

            // 6. Verknüpfen und finalisieren
            $video->setSourceFile($file);
            $this->entityManager->persist($video);
            $this->entityManager->flush();

            $this->entityManager->getConnection()->commit();
            $this->logger->info('VideoCreationService: Transaction committed successfully for video ' . $videoId);
        } catch (Exception $e) {
            $this->entityManager->getConnection()->rollBack();
            $this->logger->error('VideoCreationService: Transaction failed: ' . $e->getMessage());
            throw $e;
        }

        if ($autoProcess) {
            $video->setStatus(VideoStatus::QUEUED);
            $this->entityManager->flush();
            $this->importQueueManager->processQueue();
        }

        return $video;
    }
}
