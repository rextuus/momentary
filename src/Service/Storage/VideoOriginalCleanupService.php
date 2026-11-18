<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Entity\Video;
use App\Enum\VideoStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Räumt doppelte/temporäre Videodaten auf:
 *  - Original-Datei: Nach erfolgreicher Verarbeitung wird die konvertierte MP4 zur Quelldatei,
 *    das (meist deutlich größere) Original wird gelöscht. Ausnahme: interlaced Originale (Camcorder-Bänder) bleiben,
 *    weil die MP4 deinterlacet ist und eine spätere KI-Aufbereitung die echten Halbbilder braucht; nur mit
 *    $includeInterlaced (app:video:cleanup-originals --include-interlaced) werden auch sie gelöscht.
 *  - Analyse-Frames (frames/{id}): werden nach Abschluss nicht mehr gebraucht.
 *  - Verwaiste Ordner (frames/{id}, videos/{id} ohne zugehöriges Video).
 */
final class VideoOriginalCleanupService
{
    /** Zentrale Richtlinien: false = wird NICHT automatisch gelöscht. */
    public const DELETE_ORIGINAL_AFTER_COMPLETION = true;
    public const DELETE_FRAMES_AFTER_COMPLETION = true;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileManager $fileManager,
        private readonly FileStorageService $fileStorageService,
        private readonly StoragePathProvider $pathProvider,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Wendet alle aktivierten Richtlinien auf ein fertig verarbeitetes Video an.
     *
     * @return int freigegebene (bzw. im Dry-Run freigebbare) Bytes
     */
    public function cleanupCompleted(Video $video, bool $dryRun = false, bool $includeInterlaced = false): int
    {
        $bytes = 0;

        if (self::DELETE_ORIGINAL_AFTER_COMPLETION) {
            $bytes += $this->cleanupOriginal($video, $dryRun, $includeInterlaced);
        }
        if (self::DELETE_FRAMES_AFTER_COMPLETION) {
            $bytes += $this->cleanupFrames($video, $dryRun);
        }

        return $bytes;
    }

    public function cleanupOriginal(Video $video, bool $dryRun = false, bool $includeInterlaced = false): int
    {
        if ($video->getStatus() !== VideoStatus::COMPLETED) {
            return 0;
        }

        if ($video->isInterlaced() && !$includeInterlaced) {
            $this->logger->info(sprintf(
                'Cleanup: Video %d übersprungen (interlaced Original bleibt).',
                $video->getId(),
            ));

            return 0;
        }

        $source = $video->getSourceFile();
        $converted = $video->getConvertedFile();

        // MP4-Upload (keine zweite Datei) oder bereits bereinigt
        if ($source === null || $converted === null || $source->getId() === $converted->getId()) {
            return 0;
        }

        // Jellyfin hat sein eigenes Exemplar erhalten (Upload hat stattgefunden)
        if ($video->getJellyfinPath() === null || $video->getJellyfinPath() === '') {
            $this->logger->info(sprintf('Cleanup: Video %d übersprungen (Jellyfin-Upload fehlt).', $video->getId()));

            return 0;
        }

        $root = $this->pathProvider->getStorageRoot();
        $convertedPath = $root . '/' . $converted->getRelativePath();

        // Sicherheitsnetz: nur löschen, wenn die Ersatzdatei wirklich existiert
        if (!is_file($convertedPath) || filesize($convertedPath) === 0) {
            $this->logger->warning(sprintf(
                'Cleanup: Video %d übersprungen (konvertierte Datei fehlt/leer).',
                $video->getId(),
            ));

            return 0;
        }

        $sourcePath = $root . '/' . $source->getRelativePath();
        $bytes = is_file($sourcePath) ? (int) filesize($sourcePath) : 0;

        if ($dryRun) {
            return $bytes;
        }

        // Erst umhängen (kein FK-Konflikt), dann Original löschen
        $video->setSourceFile($converted);
        $video->setConvertedFile(null);
        $this->entityManager->flush();

        $this->fileManager->deleteFile($source, true);

        $this->logger->info(sprintf(
            'Cleanup: Video %d: Original gelöscht, %.1f MB frei.',
            $video->getId(),
            $bytes / 1048576,
        ));

        return $bytes;
    }

    public function cleanupFrames(Video $video, bool $dryRun = false): int
    {
        if ($video->getStatus() !== VideoStatus::COMPLETED) {
            return 0;
        }

        $relative = 'frames/' . $video->getId();
        $absolute = $this->pathProvider->getStorageRoot() . '/' . $relative;

        if (!is_dir($absolute)) {
            return 0;
        }

        $bytes = $this->directorySize($absolute);

        if (!$dryRun) {
            $this->fileStorageService->deleteDirectory($relative);
            $this->logger->info(sprintf(
                'Cleanup: Video %d: Frames gelöscht, %.1f MB frei.',
                $video->getId(),
                $bytes / 1048576,
            ));
        }

        return $bytes;
    }

    /**
     * Löscht frames/{id} und videos/{id}, zu denen es kein Video mehr gibt.
     * Ordner, die jünger als $minAgeSeconds sind, werden übersprungen (laufender Upload).
     *
     * @param int[] $existingVideoIds
     *
     * @return array<string, int> relativer Pfad => Bytes
     */
    public function cleanupOrphanDirectories(
        array $existingVideoIds,
        bool $dryRun = false,
        int $minAgeSeconds = 21600,
    ): array {
        $root = $this->pathProvider->getStorageRoot();
        $existing = array_flip(array_map('intval', $existingVideoIds));
        $result = [];

        foreach (['frames', 'videos'] as $base) {
            $baseDir = $root . '/' . $base;
            if (!is_dir($baseDir)) {
                continue;
            }

            foreach (scandir($baseDir) ?: [] as $entry) {
                if (!ctype_digit($entry) || isset($existing[(int) $entry])) {
                    continue;
                }

                $absolute = $baseDir . '/' . $entry;
                if (!is_dir($absolute) || (time() - (int) filemtime($absolute)) < $minAgeSeconds) {
                    continue;
                }

                $relative = $base . '/' . $entry;
                $result[$relative] = $this->directorySize($absolute);

                if (!$dryRun) {
                    $this->fileStorageService->deleteDirectory($relative);
                    $this->logger->info('Cleanup: verwaisten Ordner gelöscht: ' . $relative);
                }
            }
        }

        return $result;
    }

    private function directorySize(string $dir): int
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $dir,
            \FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }
}
