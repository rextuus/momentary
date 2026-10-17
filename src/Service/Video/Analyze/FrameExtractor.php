<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use App\Repository\VideoRepository;
use App\Service\Video\Analyze\Result\FrameSplittingResult;
use App\Service\VideoFileService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class FrameExtractor
{
    private string $pythonBinaryPath;

    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly VideoFileService $videoFileService,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(default:app.frame_analysis_fps:FRAME_ANALYSIS_FPS)%')]
        private readonly float $defaultFps = 0.2,
        #[Autowire('%env(PYTHON_BINARY)%')]
        string $pythonBinary = '/usr/bin/python3',
    ) {
        $this->pythonBinaryPath = file_exists($pythonBinary) ? $pythonBinary : 'python3';
    }

    /**
     * @param float|list<float>|null $startTime ein Startpunkt oder mehrere Segmente
     * @param float|list<float>|null $duration  Dauer passend zu $startTime
     */
    public function extractFrames(
        int $videoId,
        string $videoPath,
        ?float $fps = null,
        array|float|null $startTime = null,
        array|float|null $duration = null,
        bool $isRefinement = false,
    ): FrameSplittingResult {
        $video = $this->videoRepository->find($videoId);
        $fps ??= $video?->getAnalysisFps() ?? $this->defaultFps;

        // Verzeichnis über VideoFileService ermitteln
        $absoluteDir = $this->videoFileService->getAbsolutePath('frames/' . $videoId);
        $frameDirPath = $absoluteDir . '/' . ($isRefinement ? 'refinement' : 'analysis');

        // Frische Ausgabe: alte Frames dieses Durchlaufs entfernen, Ordner neu anlegen
        $fs = new Filesystem();
        if (is_dir($frameDirPath)) {
            $fs->remove($frameDirPath);
        }
        $fs->mkdir($frameDirPath);

        // Sicherstellen, dass der übergebene Pfad absolut ist
        $resolvedVideoPath = str_starts_with($videoPath, '/')
            ? $videoPath
            : $this->videoFileService->getAbsolutePath($videoPath);

        $command = [
            $this->pythonBinaryPath,
            $this->projectDir . '/video-analyzer/python/extract_frames.py',
            $resolvedVideoPath,
            (string) $fps,
            '--output-dir',
            $frameDirPath,
        ];

        foreach ((array) $startTime as $s) {
            $command[] = '--start-time';
            $command[] = (string) $s;
        }

        foreach ((array) $duration as $d) {
            $command[] = '--duration';
            $command[] = (string) $d;
        }

        $process = new Process($command);
        $process->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new FrameExtractionException(sprintf(
                'extract_frames.py failed (exit code %s): %s',
                (string) $process->getExitCode(),
                trim($process->getErrorOutput()) ?: trim($process->getOutput()),
            ));
        }

        $frameList = json_decode($process->getOutput(), true);
        if (!is_array($frameList)) {
            throw new FrameExtractionException(
                'extract_frames.py returned no valid JSON: ' . substr(trim($process->getOutput()), 0, 300),
            );
        }

        return new FrameSplittingResult($frameList, $frameDirPath);
    }
}
