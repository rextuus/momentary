<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use App\Repository\VideoRepository;
use App\Service\Video\Analyze\Result\FrameSplittingResult;
use App\Service\VideoFileService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class FrameExtractor
{
    private string $pythonBinaryPath;

    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly VideoFileService $videoFileService,
        private readonly PathResolver $pathResolver,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(default:app.frame_analysis_fps:FRAME_ANALYSIS_FPS)%')]
        private readonly float $defaultFps = 0.2,
        #[Autowire('%env(PYTHON_BINARY)%')]
        string $pythonBinary = '/usr/bin/python3',
    ) {
        // Fallback für Docker: Wenn der konfigurierte Python-Pfad nicht existiert,
        // nutzen wir den systemweiten python3 Befehl.
        if (!file_exists($pythonBinary)) {
            $this->pythonBinaryPath = 'python3';
        } else {
            $this->pythonBinaryPath = $pythonBinary;
        }
    }

    public function extractFrames(
        int $videoId,
        string $videoPath,
        ?float $fps = null,
        array|float|null $startTime = null,
        array|float|null $duration = null,
        bool $markLastAsFinal = true,
        bool $isRefinement = false
    ): FrameSplittingResult {
        $video = $this->videoRepository->find($videoId);
        $fps ??= $video?->getAnalysisFps() ?? $this->defaultFps;

        // Eindeutiges Verzeichnis für diese Extraktion (Video ID + Zeitstempel/Zufall)
        $dir = $this->videoFileService->getVideoDirectory($video, 'frames');
        $absoluteDir = $this->videoFileService->getAbsolutePath($dir);

        // Fester Ordner für diese Extraktion (Video ID + Typ)
        $subDir = $isRefinement ? 'refinement' : 'analysis';
        $frameDirPath = $absoluteDir . '/' . $subDir;

        if (!is_dir($frameDirPath)) {
            mkdir($frameDirPath, 0777, true);
        } else {
            // Falls der Ordner schon existiert, leeren wir ihn sicherheitshalber
            $fs = new Filesystem();
            $fs->remove(glob($frameDirPath . '/*'));
        }

        $command = [
            $this->pythonBinaryPath,
            $this->projectDir . '/video-analyzer/python/extract_frames.py',
            $this->pathResolver->resolvePath($videoPath),
            (string) $fps,
            '--output-dir',
            $frameDirPath
        ];

        if ($startTime !== null) {
            if (is_array($startTime)) {
                foreach ($startTime as $s) {
                    $command[] = '--start-time';
                    $command[] = (string) $s;
                }
            } else {
                $command[] = '--start-time';
                $command[] = (string) $startTime;
            }
        }

        if ($duration !== null) {
            if (is_array($duration)) {
                foreach ($duration as $d) {
                    $command[] = '--duration';
                    $command[] = (string) $d;
                }
            } else {
                $command[] = '--duration';
                $command[] = (string) $duration;
            }
        }

        $process = new Process($command);

        $process->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new FrameExtractionException('extract_frames.py script dont run successfully.');
        }

        $frameList = json_decode($process->getOutput(), true);

        return new FrameSplittingResult($frameList, $frameDirPath);
    }
}
