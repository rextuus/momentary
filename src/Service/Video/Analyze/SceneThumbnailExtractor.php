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
        private readonly VideoFileService $videoFileService,
        #[Autowire('%env(PYTHON_BINARY)%')]
        string $pythonBinary = '/usr/bin/python3',
    ) {
    }

    public function extractThumbnail(Video $video, float $timeInSeconds = 0.0, ?string $customFilename = null): ?string
    {
        $sourceFile = $video->getConvertedFilename() ?? $video->getSourceFile();

        if (!$sourceFile) {
            return null;
        }

        $videoPath = $this->videoFileService->getAbsolutePath($sourceFile);

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
                        $timeInSeconds = $duration * (mt_rand(10, 90) / 100);
                    }
                } else {
                    $timeInSeconds = 1.0;
                }
            } catch (\Exception $e) {
                $timeInSeconds = 1.0;
            }
        }

        // Zielverzeichnis für Thumbnails definieren (z. B. im Public/Upload-Ordner oder über Flysystem)
        $thumbnailDir = 'thumbnails/' . $video->getId();
        $absoluteDir = $this->videoFileService->getAbsolutePath($thumbnailDir);
        if (!is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0777, true);
        }

        $thumbnailName = $customFilename ?? sprintf('video_%d.jpg', $video->getId());
        $thumbnailPath = $absoluteDir . '/' . $thumbnailName;

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

        if (!$process->isSuccessful() || !file_exists($thumbnailPath)) {
            return null;
        }

        @touch($thumbnailPath);

        $relativeThumbnailPath = $thumbnailDir . '/' . $thumbnailName;
        $relativeThumbnailPath .= '?t=' . time();

        if ($customFilename === null) {
            // Nutze das korrekte Entity-Feld für das Thumbnail (z.B. setThumbnailUrl)
            $video->setThumbnailUrl($relativeThumbnailPath);
            $this->entityManager->flush();
        }

        return $relativeThumbnailPath;
    }
}