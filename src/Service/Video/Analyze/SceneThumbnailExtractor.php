<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use App\Entity\Video;
use App\Service\VideoFileService;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly PathResolver $pathResolver,
        private readonly VideoFileService $videoFileService,
        #[Autowire('%env(PYTHON_BINARY)%')]
        string $pythonBinary = '/usr/bin/python3',
    ) {
    }

    public function extractThumbnail(Video $video, float $timeInSeconds = 0.0, ?string $customFilename = null): ?string
    {
        $videoPath = $video->getConvertedVideoPath();
        if (!$videoPath || str_starts_with($videoPath, 'defaults/')) {
            $videoPath = $video->getLocalPath();
        }


        if (!$videoPath) {
            return null;
        }

        $videoPath = $this->pathResolver->resolvePath($videoPath);
        if (!file_exists($videoPath)) {
            return null;
        }

        // Wenn Zeit 0.0 ist, versuchen wir eine sinnvollere Zeit zu finden (zufällig)
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
                        // Wähle einen zufälligen Zeitpunkt zwischen 10% und 90%
                        $timeInSeconds = $duration * (mt_rand(10, 90) / 100);
                    }
                } else {
                    $timeInSeconds = 1.0;
                }
            } catch (\Exception $e) {
                $timeInSeconds = 1.0; // Fallback auf 1 Sekunde
            }
        }

        $thumbnailDir = $this->videoFileService->getVideoDirectory($video, 'thumbnails');
        $absoluteDir = $this->videoFileService->getAbsolutePath($thumbnailDir);
        if (!is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0777, true);
        }

        $thumbnailName = $customFilename ?? sprintf('video_%d.jpg', $video->getId());
        $thumbnailPath = $absoluteDir . '/' . $thumbnailName;

        // FFmpeg Kommando um ein einzelnes Frame zu extrahieren
        $command = [
            'ffmpeg',
            '-loglevel', 'error',
            '-y',
            '-ss', (string)$timeInSeconds,
            '-i', $videoPath,
            '-vframes', '1',
            '-q:v', '2',
            '-pix_fmt', 'yuvj420p',
            $thumbnailPath
        ];

        $process = new Process($command);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (RuntimeException $e) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        // Verifizieren, dass die Datei existiert und aktualisiert wurde
        if (file_exists($thumbnailPath)) {
            @touch($thumbnailPath); // Zeitstempel aktualisieren, falls Größe identisch war
        } else {
            return null;
        }

        $relativeThumbnailPath = $thumbnailDir . '/' . $thumbnailName;

        // Cache-Buster hinzufügen, um Browser-Caching zu umgehen
        $relativeThumbnailPath .= '?t=' . time();

        if ($customFilename === null) {
            $video->setThumbnailPath($relativeThumbnailPath);
            $this->entityManager->flush();
        }

        return $relativeThumbnailPath;
    }
}
