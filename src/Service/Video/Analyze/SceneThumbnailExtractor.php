<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use App\Entity\File;
use App\Entity\Video;
use App\Service\Storage\FileManager;
use App\Service\Storage\FileStorageService;
use App\Service\Storage\StoragePathProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * @author Wolfgang Hinzmann <wolfgang.hinzmann@doccheck.com>
 * @license 2026 DocCheck Community GmbH
 */
class SceneThumbnailExtractor
{
    public function __construct(
        private readonly StoragePathProvider $pathProvider,
        private readonly FileManager $fileManager,
        private readonly FileStorageService $storageService,
        #[Autowire('%env(PYTHON_BINARY)%')]
        string $pythonBinary = '/usr/bin/python3',
    ) {
    }

    public function extractThumbnail(Video $video, float $timeInSeconds = 0.0, ?string $customFilename = null): ?File
    {
        $fileEntity = $video->getConvertedFile() ?? $video->getSourceFile();
        if ($fileEntity === null) {
            return null;
        }

        $videoPath = $this->pathProvider->getAbsolutePath($fileEntity);
        if (!file_exists($videoPath)) {
            return null;
        }

        // Wenn Zeit 0.0 ist, Dauer per ffprobe ermitteln
        if ($timeInSeconds <= 0.0) {
            try {
                $ffprobeProcess = new Process([
                    'ffprobe',
                    '-v', 'error',
                    '-show_entries', 'format=duration',
                    '-of', 'default=noprint_wrappers=1:nokey=1',
                    $videoPath
                ]);
                $ffprobeProcess->run();
                if ($ffprobeProcess->isSuccessful()) {
                    $duration = (float) $ffprobeProcess->getOutput();
                    if ($duration > 0) {
                        $timeInSeconds = $duration * (mt_rand(10, 90) / 100);
                    }
                } else {
                    $timeInSeconds = 1.0;
                }
            } catch (\Exception $e) {
                $timeInSeconds = 1.0;
            }
        }

        $thumbnailName = $customFilename ?? sprintf('video_%d.jpg', $video->getId());

        // Thumbnail-File-Entity erzeugen und absoluten Pfad über den PathProvider holen
        $thumbnailFile = $this->fileManager->createVideoThumbnailFile($video, $thumbnailName);
        $this->storageService->ensureDirectoryExists($thumbnailFile);
        $absoluteThumbnailPath = $this->pathProvider->getAbsolutePath($thumbnailFile);

        $command = [
            'ffmpeg',
            '-loglevel', 'error',
            '-y',
            '-ss', (string)$timeInSeconds,
            '-i', $videoPath,
            '-vframes', '1',
            '-q:v', '2',
            '-pix_fmt', 'yuvj420p',
            $absoluteThumbnailPath
        ];

        $process = new Process($command);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (RuntimeException $e) {
            return null;
        }

        if (!$process->isSuccessful()) {
            error_log("DEBUG: ffmpeg failed: " . $process->getErrorOutput());
            return null;
        }

        if (!file_exists($absoluteThumbnailPath)) {
            error_log("DEBUG: File does not exist after ffmpeg: " . $absoluteThumbnailPath);
            return null;
        }

        @touch($absoluteThumbnailPath);

        // Dateigröße aktualisieren und Datei über den FileManager persistieren
        $thumbnailFile->setFileSize(filesize($absoluteThumbnailPath) ?: 0);
        $this->fileManager->saveFile($thumbnailFile);

        return $thumbnailFile;
    }
}