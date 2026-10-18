<?php

declare(strict_types=1);

namespace App\Service\Photo;

use App\Entity\File;
use App\Entity\Medium;
use App\Entity\Photo;
use App\Enum\FilePurpose;
use App\Enum\PhotoStatus;
use App\Service\Aws\AmazonRekognitionService;
use App\Service\Face\OrphanedPersonCleaner;
use App\Service\Gemini\GeminiService;
use App\Service\Geo\PlaceTagger;
use App\Service\Media\CameraClock;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Analyze\FrameAnalyzer;
use App\Service\Video\Analyze\TagService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Analysiert ein Foto: Anzeige-Fassung und Vorschau erzeugen, EXIF übernehmen, Gesichter erkennen (wie bei
 * Video-Frames, gleiche Personen), Titel und Tags von Gemini, Suchindex.
 */
class PhotoAnalyzer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StoragePathProvider $pathProvider,
        private readonly PhotoProcessor $processor,
        private readonly FrameAnalyzer $frameAnalyzer,
        private readonly AmazonRekognitionService $rekognition,
        private readonly OrphanedPersonCleaner $orphanedPersonCleaner,
        private readonly GeminiService $gemini,
        private readonly TagService $tagService,
        private readonly PhotoSearchIndex $searchIndex,
        private readonly PlaceTagger $placeTagger,
        private readonly CameraClock $cameraClock,
        private readonly PhotoBursts $bursts,
        private readonly LoggerInterface $logger,
    ) {}

    public function analyze(Photo $photo): void
    {
        $photo->setStatus(PhotoStatus::PROCESSING);
        $this->entityManager->flush();

        try {
            $source = $photo->getSourceFile();
            $sourcePath = $source !== null ? $this->pathProvider->getAbsolutePath($source) : null;
            if ($sourcePath === null || !is_file($sourcePath)) {
                throw new \RuntimeException('Die Originaldatei des Fotos fehlt.');
            }

            $display = $this->file($photo, $photo->getDisplayFile(), 'display.jpg', FilePurpose::PHOTO_DISPLAY);
            $thumbnail = $this->file($photo, $photo->getThumbnailFile(), 'thumbnail.jpg', FilePurpose::PHOTO_THUMBNAIL);
            $displayPath = $this->pathProvider->getAbsolutePath($display);
            $meta = $this->processor->process(
                $sourcePath,
                $displayPath,
                $this->pathProvider->getAbsolutePath($thumbnail),
            );

            $display->setFileSize((int) filesize($displayPath));
            $thumbnail->setFileSize((int) filesize($this->pathProvider->getAbsolutePath($thumbnail)));
            $photo->setDisplayFile($display)->setThumbnailFile($thumbnail);
            $photo->setDimensions($meta['width'], $meta['height']);
            $photo->setLocation($meta['latitude'], $meta['longitude']);
            $photo->setCamera($meta['camera']);
            $photo->setImageMetrics($meta['fingerprint'], $meta['sharpness']);
            // Eine vorhandene (z. B. von Hand korrigierte) Aufnahmezeit bleibt
            if ($photo->getTakenAt() === null) {
                $photo->setRecordedAt($meta['takenAt'], Medium::DATE_EXIF);
                $this->cameraClock->adjust($photo);
            }
            $this->entityManager->flush();

            $this->removeFaces($photo);
            $this->frameAnalyzer->analyzeImage($photo, $displayPath, 0, null, $display);

            $this->tag($photo, $displayPath);
            if ($photo->getPlaceName() === null) {
                $this->placeTagger->tag($photo);
            }

            $photo->setStatus(PhotoStatus::COMPLETED);
            $this->entityManager->flush();
            // Erst jetzt: das beste Foto einer Serie hängt auch von den erkannten Gesichtern ab
            $this->bursts->regroupAround($photo);
        } catch (\Throwable $e) {
            $photo->fail($e->getMessage());
            $this->entityManager->flush();
            $this->logger->error(sprintf('Foto %d: %s', (int) $photo->getId(), $e->getMessage()));

            return;
        }

        try {
            $this->searchIndex->index($photo);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Suchindex für Foto %d: %s', (int) $photo->getId(), $e->getMessage()));
        }
    }

    private function file(Photo $photo, ?File $existing, string $name, FilePurpose $purpose): File
    {
        if ($existing !== null) {
            return $existing;
        }

        $file = new File();
        $file->setRelativePath($this->pathProvider->createPhotoPath((string) $photo->getId(), $name));
        $file->setMimeType('image/jpeg');
        $file->setFileSize(0);
        $file->setPurpose($purpose);
        $this->entityManager->persist($file);

        return $file;
    }

    /**
     * Bei erneuter Analyse: alte Gesichter samt AWS-Einträgen entfernen und dadurch leere Personen aufräumen.
     */
    private function removeFaces(Photo $photo): void
    {
        if ($photo->getFaces()->isEmpty()) {
            return;
        }

        $personIds = $this->orphanedPersonCleaner->personIdsOfMedium((int) $photo->getId());
        $labels = [];
        foreach ($photo->getFaces()->toArray() as $face) {
            if ($face->getFaceLabel() !== null) {
                $labels[] = $face->getFaceLabel();
            }
            $face->getPerson()?->removeVideoFace($face);
            $photo->removeFace($face);
            $this->entityManager->remove($face);
        }
        $this->entityManager->flush();

        try {
            $this->rekognition->deleteFaces($labels);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('AWS-Gesichter von Foto %d: %s', (int) $photo->getId(), $e->getMessage()));
        }
        $this->orphanedPersonCleaner->removeOrphans($personIds);
    }

    /**
     * Titel und Tags von Gemini; scheitert das, bleibt das Foto trotzdem nutzbar.
     */
    private function tag(Photo $photo, string $displayPath): void
    {
        try {
            $result = $this->gemini->analyzeImage($displayPath);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Gemini für Foto %d: %s', (int) $photo->getId(), $e->getMessage()));

            return;
        }

        if (
            self::isCameraFileName((string) $photo->getTitle())
            && \is_string($result['Titel'] ?? null)
            && trim($result['Titel']) !== ''
        ) {
            $photo->setTitle(trim($result['Titel']));
        }

        $tags = \is_array($result['Tags'] ?? null) ? $result['Tags'] : [];
        $this->tagService->assignAiTagsToMedium($photo, $tags);
    }

    /**
     * Titel ist noch der Dateiname der Kamera (z. B. "IMG_1234", "20250319_133537", "PXL_20250319_101500123")
     * und darf durch einen sprechenden Titel ersetzt werden; von Hand vergebene Titel bleiben.
     */
    public static function isCameraFileName(string $title): bool
    {
        return $title === '' || preg_match('/^([A-Z]{2,6}[_\-]?)?\d[\d_\-]*(\s?\(\d+\))?$/i', $title) === 1;
    }
}
