<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Person;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Entity\VideoScene;
use App\Enum\VideoStatus;
use App\Message\FrameAnalyzerMessage;
use App\Repository\VideoRepository;
use App\Service\WorkflowMachine;
use App\Service\Aws\AmazonRekognitionService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Process;

class VideoAnalyzer
{
    private string $pythonBinaryPath;

    public function __construct(
        private MessageBusInterface $bus,
        private FilesystemOperator $filesystem,
        private EntityManagerInterface $entityManager,
        private VideoRepository $videoRepository,
        private AmazonRekognitionService $rekognitionService,
        private LoggerInterface $logger,
        private WorkflowMachine $workflowMachine,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire('%env(PYTHON_BINARY)%')]
        string $pythonBinary = '/usr/bin/python3',
        #[Autowire('%env(default:app.frame_analysis_fps:FRAME_ANALYSIS_FPS)%')]
        private float $defaultFps = 0.2,
        #[Autowire('%env(default:app.min_scene_length_for_refinement:MIN_SCENE_LENGTH_FOR_REFINEMENT)%')]
        private float $minSceneLengthForRefinement = 2.0,
        #[Autowire('%env(default:app.refined_frame_analysis_fps:REFINED_FRAME_ANALYSIS_FPS)%')]
        private float $refinedFps = 1.0
    ) {
        // Fallback für Docker: Wenn der konfigurierte Python-Pfad nicht existiert,
        // nutzen wir den systemweiten python3 Befehl.
        if (!file_exists($pythonBinary)) {
            $this->pythonBinaryPath = 'python3';
        } else {
            $this->pythonBinaryPath = $pythonBinary;
        }
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    public function extractThumbnail(Video $video, float $timeInSeconds = 0.0): ?string
    {
        $videoPath = $video->getConvertedVideoPath();
        if (!$videoPath || str_starts_with($videoPath, 'defaults/')) {
            $videoPath = $video->getLocalPath();
        }

        $this->logger->info(sprintf('Extracting thumbnail for video %d at %fs. Original path: %s', $video->getId(), $timeInSeconds, $videoPath ?: 'NULL'));

        if (!$videoPath) {
            return null;
        }

        $videoPath = $this->resolvePath($videoPath);
        $this->logger->info(sprintf('Resolved video path for thumbnail: %s', $videoPath));
        if (!file_exists($videoPath)) {
            $this->logger->error("Video file not found for thumbnail extraction: " . $videoPath);
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
                        $this->logger->info(sprintf('Generated random thumbnail time %fs for video %d (duration %fs)', $timeInSeconds, $video->getId(), $duration));
                    }
                } else {
                    $this->logger->warning('ffprobe failed to get duration: ' . $ffprobeProcess->getErrorOutput());
                    $timeInSeconds = 1.0;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Could not determine video duration for random thumbnail: ' . $e->getMessage());
                $timeInSeconds = 1.0; // Fallback auf 1 Sekunde
            }
        }

        $thumbnailDir = $this->projectDir . '/public/uploads/thumbnails';
        if (!is_dir($thumbnailDir)) {
            mkdir($thumbnailDir, 0777, true);
        }

        $thumbnailName = sprintf('video_%d.jpg', $video->getId());
        $thumbnailPath = $thumbnailDir . '/' . $thumbnailName;

        $this->logger->info(sprintf('Thumbnail will be saved to: %s', $thumbnailPath));

        // FFmpeg Kommando um ein einzelnes Frame zu extrahieren
        // -ss vor -i für schnelles Seek
        $command = [
            'ffmpeg',
            '-loglevel', 'error',
            '-y',
            '-ss', (string)$timeInSeconds,
            '-i', $videoPath,
            '-vframes', '1',
            '-q:v', '2',
            $thumbnailPath
        ];

        $process = new Process($command);
        $process->setTimeout(60);
        $this->logger->info(sprintf('Running FFmpeg: ' . implode(' ', $command)));
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error('Thumbnail extraction failed: ' . $process->getErrorOutput());
            $this->logger->error('Command used: ' . implode(' ', $command));
            return null;
        }

        // Verifizieren, dass die Datei existiert und aktualisiert wurde
        if (file_exists($thumbnailPath)) {
            $this->logger->info(sprintf('Thumbnail file successfully created/updated: %s (Size: %d bytes)', $thumbnailPath, filesize($thumbnailPath)));
            @touch($thumbnailPath); // Zeitstempel aktualisieren, falls Größe identisch war
        } else {
            $this->logger->error('FFmpeg reported success, but thumbnail file not found at: ' . $thumbnailPath);
            return null;
        }

        $relativeThumbnailPath = 'uploads/thumbnails/' . $thumbnailName;
        
        // Cache-Buster hinzufügen, um Browser-Caching zu umgehen
        $relativeThumbnailPath .= '?t=' . time();
        
        $video->setThumbnailPath($relativeThumbnailPath);
        $this->entityManager->flush();

        return $relativeThumbnailPath;
    }

    public function getVideoRepository(): VideoRepository
    {
        return $this->videoRepository;
    }

    /**
     * Stellt sicher, dass ein Pfad in der aktuellen Umgebung gültig ist.
     * Insbesondere werden Docker-spezifische Pfade (/var/www/html/...) 
     * in lokale Pfade übersetzt, falls die App außerhalb von Docker läuft.
     */
    public function resolvePath(string $path): string
    {
        // Wenn der Pfad bereits existiert, ist alles gut
        if (file_exists($path)) {
            return $path;
        }

        // Falls er absolut ist und aus Docker stammt
        if (str_starts_with($path, '/var/www/html/')) {
            $relativePath = str_replace('/var/www/html/', '', $path);
            $localPath = $this->projectDir . '/' . $relativePath;
            
            if (file_exists($localPath)) {
                return $localPath;
            }
        }

        // Falls er bereits relativ ist (oder wir ihn relativ gemacht haben)
        $cleanPath = ltrim($path, '/');
        
        // Wenn der Pfad mit "public/" beginnt, versuchen wir es auch ohne "public/", 
        // da im Docker-Kontext das "public/" oft das Root-Verzeichnis des Webservers ist
        // und Dateien relativ zum Projektroot in "public/..." liegen.
        $pathsToTry = [
            $this->projectDir . '/' . $cleanPath,
        ];
        
        if (str_starts_with($cleanPath, 'public/')) {
            $pathsToTry[] = $this->projectDir . '/' . substr($cleanPath, 7);
        }

        foreach ($pathsToTry as $projectPath) {
            if (file_exists($projectPath)) {
                return $projectPath;
            }
        }

        return $path;
    }

    /**
     * Macht einen absoluten Pfad zu einem relativen Pfad (innerhalb des Projekts).
     */
    public function makePathRelative(string $path): string
    {
        $dockerPrefix = '/var/www/html/';
        if (str_starts_with($path, $dockerPrefix)) {
            return str_replace($dockerPrefix, '', $path);
        }

        if (str_starts_with($path, $this->projectDir)) {
            return ltrim(str_replace($this->projectDir, '', $path), '/');
        }

        return $path;
    }

    public function updateStatus(int $videoId, VideoStatus $status, ?string $localPath = null, ?string $errorMessage = null): void
    {
        $video = $this->videoRepository->find($videoId);
        if ($video) {
            $oldStatus = $video->getStatus();

            // Mapping VideoStatus to transitions
            $transition = match ($status) {
                VideoStatus::DOWNLOADING => 'start_download',
                VideoStatus::CONVERTING => 'start_conversion',
                VideoStatus::SCENE_DETECTION => 'start_scene_detection',
                VideoStatus::SPLITTING => 'start_splitting',
                VideoStatus::ANALYZING_FACES => 'start_analyzing',
                VideoStatus::REFINING_EXTRACTION => 'start_refining_extraction',
                VideoStatus::REFINING_ANALYSIS => 'start_refining_analysis',
                VideoStatus::MERGING_SCENES => 'start_merging',
                VideoStatus::COMPLETED => 'complete',
                VideoStatus::ERROR => 'fail',
                VideoStatus::PENDING => 'reset',
                default => null
            };

            if ($transition && $this->workflowMachine->can($video, $transition)) {
                $this->workflowMachine->apply($video, $transition);
            } else {
                // Fallback to manual set if no transition matches or is allowed
                $video->setStatus($status);
            }

            if ($localPath) {
                // Wir speichern Pfade bevorzugt relativ, um umgebungsunabhängig zu sein
                $video->setLocalPath($this->makePathRelative($localPath));
            }
            if ($errorMessage !== null || $status !== VideoStatus::ERROR) {
                $video->setErrorMessage($errorMessage);
            }

            // Zeitstempel setzen und Dauer berechnen
            $now = new \DateTimeImmutable();
            $this->calculateDuration($video, $oldStatus, $now);

            match ($status) {
                VideoStatus::DOWNLOADING => $this->setEstimates($video),
                VideoStatus::CONVERTING => $video->setDownloadedAt($video->getDownloadedAt() ?? $now),
                VideoStatus::SCENE_DETECTION => $video->setConvertedAt($video->getConvertedAt() ?? $video->getDownloadedAt() ?? $now),
                VideoStatus::SPLITTING => $video->setScenesDetectedAt($video->getScenesDetectedAt() ?? $now),
                VideoStatus::ANALYZING_FACES => $video->setFramesExtractedAt($video->getFramesExtractedAt() ?? $now),
                VideoStatus::REFINING_EXTRACTION => $video->setFacesAnalyzedAt($video->getFacesAnalyzedAt() ?? $now),
                VideoStatus::REFINING_ANALYSIS => $video->setRefiningExtractionFinishedAt($video->getRefiningExtractionFinishedAt() ?? $now),
                VideoStatus::MERGING_SCENES => $video->setRefiningAnalysisFinishedAt($video->getRefiningAnalysisFinishedAt() ?? $now),
                VideoStatus::COMPLETED => $video->setCompletedAt($now),
                default => null,
            };

            // Spezialfall: Wenn direkt COMPLETED ohne REFINING/MERGING
            if ($status === VideoStatus::COMPLETED) {
                if ($video->getFacesAnalyzedAt() === null) {
                    $video->setFacesAnalyzedAt($now);
                }
                if ($video->getRefiningAnalysisFinishedAt() === null) {
                    $video->setRefiningAnalysisFinishedAt($now);
                }
                if ($video->getMergingScenesAt() === null) {
                    $video->setMergingScenesAt($now);
                }
            }

            if ($status === VideoStatus::MERGING_SCENES) {
                $video->setMergingScenesAt($now);
            }

            $this->entityManager->flush();
        }
    }

    private function calculateDuration(Video $video, VideoStatus $oldStatus, \DateTimeImmutable $now): void
    {
        $startTime = match ($oldStatus) {
            VideoStatus::DOWNLOADING => $video->getCreatedAt(),
            VideoStatus::CONVERTING => $video->getDownloadedAt(),
            VideoStatus::SCENE_DETECTION => $video->getConvertedAt() ?? $video->getDownloadedAt(),
            VideoStatus::SPLITTING => $video->getScenesDetectedAt(),
            VideoStatus::ANALYZING_FACES => $video->getFramesExtractedAt(),
            VideoStatus::REFINING_EXTRACTION => $video->getFacesAnalyzedAt(),
            VideoStatus::REFINING_ANALYSIS => $video->getRefiningExtractionFinishedAt(),
            VideoStatus::MERGING_SCENES => $video->getRefiningAnalysisFinishedAt(),
            default => null,
        };

        if ($startTime === null) {
            return;
        }

        $duration = $now->getTimestamp() - $startTime->getTimestamp();

        match ($oldStatus) {
            VideoStatus::DOWNLOADING => $video->setDownloadDuration($duration),
            VideoStatus::CONVERTING => $video->setConversionDuration($duration),
            VideoStatus::SCENE_DETECTION => $video->setSceneDetectionDuration($duration),
            VideoStatus::SPLITTING => $video->setFrameExtractionDuration($duration),
            VideoStatus::ANALYZING_FACES => $video->setFaceAnalysisDuration($duration),
            VideoStatus::REFINING_EXTRACTION => $video->setRefiningExtractionDuration($duration),
            VideoStatus::REFINING_ANALYSIS => $video->setRefiningAnalysisDuration($duration),
            VideoStatus::MERGING_SCENES => $video->setMergingScenesDuration($duration),
            default => null,
        };
    }

    private function setEstimates(Video $video): void
    {
        $duration = $video->getDuration();
        if (!$duration) return;

        $statuses = [
            VideoStatus::CONVERTING,
            VideoStatus::SCENE_DETECTION,
            VideoStatus::SPLITTING,
            VideoStatus::ANALYZING_FACES
        ];

        foreach ($statuses as $status) {
            $ratio = $this->videoRepository->getAverageDurationRatio($status);
            if ($ratio <= 0) {
                // Default ratios if no history exists
                $ratio = match($status) {
                    VideoStatus::CONVERTING => 0.1,
                    VideoStatus::SCENE_DETECTION => 0.2,
                    VideoStatus::SPLITTING => 0.1,
                    VideoStatus::ANALYZING_FACES => 1.5, // analysis is slow
                    default => 0.1,
                };
            }

            $estimated = (int) ($duration * $ratio);
            match($status) {
                VideoStatus::CONVERTING => $video->setEstimatedConversionDuration($estimated),
                VideoStatus::SCENE_DETECTION => $video->setEstimatedSceneDetectionDuration($estimated),
                VideoStatus::SPLITTING => $video->setEstimatedFrameExtractionDuration($estimated),
                VideoStatus::ANALYZING_FACES => $video->setEstimatedFaceAnalysisDuration($estimated),
                default => null,
            };
        }
    }

    public function downloadVideo(int $videoId, string $youtubeUrl): ?string
    {
        $this->updateStatus($videoId, VideoStatus::DOWNLOADING);

        $process = new Process([
            $this->pythonBinaryPath,
            $this->projectDir . '/video-analyzer/python/download_video.py',
            $youtubeUrl,
            '--video-id=' . $videoId
        ]);

        $process->setTimeout(300);
        $process->run();
        if (!$process->isSuccessful()) {
            // Hier ist der entscheidende Teil: Wir loggen jetzt STDOUT und STDERR!
            $errorOutput = $process->getErrorOutput();
            $stdOutput = $process->getOutput();

            $msg = "Download failed: " . $errorOutput . " | STDOUT: " . $stdOutput;

            // Schreibe es in die Logs von Symfony
            error_log($msg);

            $this->updateStatus($videoId, VideoStatus::ERROR, null, $msg);
            
            throw new \RuntimeException($msg);
        }

        $data = json_decode($process->getOutput(), true);
        $path = $data['video_path'] ?? null;
        $duration = $data['duration'] ?? null;

        // Nach dem Download speichern wir den Pfad und die Länge direkt am Video
        $video = $this->videoRepository->find($videoId);
        if ($video) {
            if ($duration) {
                $video->setDuration((float) $duration);
            }
            $this->entityManager->flush();
        }

        $this->updateStatus($videoId, VideoStatus::PENDING, $path);

        return $path;
    }

    public function detectScenes(
        string $videoPath,
        int $videoId,
        float $threshold = 27.0,
        string $detector = 'content'
    ): array {
        $video = $this->entityManager->find(Video::class, $videoId);

        // FIX: Alte Szenen löschen, bevor wir neue erkennen
        $this->clearOldScenes($video);

        $this->updateStatus($videoId, VideoStatus::SCENE_DETECTION);

        $process = new Process([
            $this->pythonBinaryPath,
            $this->projectDir . '/video-analyzer/python/detect_scenes.py',
            $videoPath,
            '--threshold',
            (string) $threshold,
            '--detector',
            $detector
        ]);

        $process->setTimeout(900);
        $process->run();

        if (!$process->isSuccessful()) {
            $msg = 'Scene detection failed: ' . $process->getErrorOutput();
            $this->updateStatus($videoId, VideoStatus::ERROR, null, $msg);

            throw new \RuntimeException($msg);
        }

        $output = $process->getOutput();
        // Wir suchen das letzte Vorkommen von '[', um nur das JSON-Array zu extrahieren,
        // falls davor Müll auf STDOUT gelandet ist.
        $startPos = strrpos($output, '[');
        if ($startPos !== false) {
            $output = substr($output, $startPos);
        }

        $data = json_decode($output, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $msg = 'Failed to decode scene detection output: ' . json_last_error_msg() . ' | Raw output: ' . $output;
            $this->updateStatus($videoId, VideoStatus::ERROR, null, $msg);
            throw new \RuntimeException($msg);
        }

        // Falls Warnungen im JSON enthalten sind, diese loggen/ausgeben
        if (count($data) === 1 && isset($data[0]['warning'])) {
            // Wenn nur eine Szene mit Warnung zurückkommt, deutet das auf technische Probleme hin
            // (z.B. Interlaced MPG). In diesem Fall versuchen wir eine automatische Konvertierung
            // und starten den Prozess erneut, falls es noch nicht die konvertierte Version war.
            if (!str_contains($videoPath, 'video_analyze_')) {
                $tempMp4Name = 'video_analyze_' . $videoId . '.mp4';
                $tempMp4 = $this->projectDir . '/public/uploads/import/' . $tempMp4Name;

                $this->updateStatus($videoId, VideoStatus::CONVERTING);

                // Konvertierung
                $convProcess = new Process([
                    'ffmpeg',
                    '-y',
                    '-i',
                    $videoPath,
                    '-c:v',
                    'libx264',
                    '-preset',
                    'ultrafast',
                    '-crf',
                    '23',
                    '-c:a',
                    'aac',
                    $tempMp4
                ]);
                $convProcess->setTimeout(1800);
                $convProcess->run();

                if ($convProcess->isSuccessful()) {
                    // Wir nutzen den konvertierten Pfad für den Rest der Pipeline
                    $video->setConvertedVideoPath($tempMp4);
                    $video->setLocalPath($tempMp4);
                    $this->entityManager->flush();

                    // FIX: Wir müssen auch den Pfad im Message-Objekt für den nächsten Schritt (Splitting) aktualisieren,
                    // aber detectScenes wird oft synchron aufgerufen oder gibt Daten zurück.
                    // Da detectScenes rekursiv aufgerufen wird, wird der neue Pfad zurückgegeben.
                    return $this->detectScenes($tempMp4, $videoId, $threshold, $detector);
                }
            }
        }

        return $data;
    }

    public function storeScenes(int $videoId, array $scenes): void
    {
        $video = $this->videoRepository->find($videoId);
        if (!$video) {
            throw new \RuntimeException("Video $videoId nicht gefunden.");
        }

        foreach ($scenes as $data) {
            $scene = new VideoScene();
            $scene->setVideo($video);
            $scene->setSceneNumber((int) $data['scene_number']);
            $scene->setStartSeconds((float) $data['start_seconds']);
            $scene->setEndSeconds((float) $data['end_seconds']);
            $this->entityManager->persist($scene);
        }

        $this->entityManager->flush();
    }

    public function extractFrames(
        int $videoId,
        string $videoPath,
        ?float $fps = null,
        array|float|null $startTime = null,
        array|float|null $duration = null,
        bool $markLastAsFinal = true,
        bool $isRefinement = false
    ): bool {
        if ($isRefinement) {
            $this->updateStatus($videoId, VideoStatus::REFINING_EXTRACTION);
        } else {
            $this->updateStatus($videoId, VideoStatus::SPLITTING);
        }

        $video = $this->videoRepository->find($videoId);
        $fps ??= $video?->getAnalysisFps() ?? $this->defaultFps;

        // Eindeutiges Verzeichnis für diese Extraktion (Video ID + Zeitstempel/Zufall)
        $frameDirName = sprintf('frames_%d_%s', $videoId, uniqid());
        $frameDirPath = sys_get_temp_dir() . '/' . $frameDirName;

        try {
            $command = [
                $this->pythonBinaryPath,
                $this->projectDir . '/video-analyzer/python/extract_frames.py',
                $this->resolvePath($videoPath),
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
                $msg = 'Frame extraction failed: ' . $process->getErrorOutput();
                $this->updateStatus($videoId, VideoStatus::ERROR, null, $msg);
                return false;
            }

            $frameList = json_decode($process->getOutput(), true);
            $video = $this->videoRepository->find($videoId);
            if ($video) {
                // Bereinigung des ALTEN Verzeichnisses, falls vorhanden
                $oldFrameDir = $isRefinement 
                    ? $video->getCurrentRefinementFrameDirectory() 
                    : $video->getCurrentFrameDirectory();
                
                if ($oldFrameDir && is_dir($oldFrameDir) && $oldFrameDir !== $frameDirPath && str_contains($oldFrameDir, 'frames_')) {
                    $fs = new \Symfony\Component\Filesystem\Filesystem();
                    $fs->remove($oldFrameDir);
                }

                $video->setTotalFrames(count($frameList));
                $video->setProcessedFrames(0);
                
                if ($isRefinement) {
                    $video->setCurrentRefinementFrameDirectory($frameDirPath);
                } else {
                    $video->setCurrentFrameDirectory($frameDirPath);
                }
                
                $this->entityManager->flush();
            }

            foreach ($frameList as $index => $frame) {
                $timestamp = (int) $frame['timestamp'];
                if ($startTime !== null) {
                    $timestamp += (int) $startTime;
                }

                $this->bus->dispatch(
                    new FrameAnalyzerMessage(
                        $videoId,
                        $frame['path'],
                        $timestamp,
                        $markLastAsFinal && $index === array_key_last($frameList), // Letztes Frame markieren
                        $isRefinement
                    )
                );
            }

            return true;
        } catch (\Throwable $e) {
            $this->updateStatus($videoId, VideoStatus::ERROR, null, $e->getMessage());
            return false;
        }
    }

    public function clearOldScenes(Video $video): void
    {
        $connection = $this->entityManager->getConnection();

            // 1. Alle Faces löschen, die an Szenen dieses Videos hängen
            $connection->executeStatement(
                'DELETE FROM video_face WHERE video_id = ?',
                [$video->getId()]
            );

            // 2. Alle Szenen des Videos löschen
            $connection->executeStatement(
                'DELETE FROM video_scene WHERE video_id = ?',
                [$video->getId()]
            );

            // 3. Fortschrittszähler zurücksetzen
            $video->setProcessedFrames(0);
            $video->setTotalFrames(0);
            $this->entityManager->flush(); // Explicit flush here

        $this->entityManager->refresh($video);
    }

    public function analyzeFrame(int $videoId, string $framePath, int $timestamp, bool $isLastFrame, bool $isRefinement = false): void
    {
        try {
            if (!file_exists($framePath)) {
                $this->logger->error('Frame file does not exist at start of analysis', [
                    'videoId' => $videoId,
                    'framePath' => $framePath,
                    'isRefinement' => $isRefinement
                ]);
                throw new \RuntimeException("Frame file does not exist: $framePath");
            }

            $this->logger->debug('Analyzing frame', [
                'videoId' => $videoId,
                'timestamp' => $timestamp,
                'isLastFrame' => $isLastFrame,
                'isRefinement' => $isRefinement
            ]);

            $video = $this->videoRepository->find($videoId);
            if (!$video) {
                return;
            }

            $frameDirPath = $isRefinement 
                ? $video->getCurrentRefinementFrameDirectory() 
                : $video->getCurrentFrameDirectory();

            if ($frameDirPath && !str_starts_with($framePath, $frameDirPath)) {
                $this->logger->warning('Frame path does not match current frame directory, ignoring to prevent race conditions', [
                    'videoId' => $videoId,
                    'framePath' => $framePath,
                    'currentFrameDir' => $frameDirPath,
                    'isRefinement' => $isRefinement
                ]);
                return;
            }

            // Wir stellen sicher, dass wir im richtigen Status sind
            if ($isRefinement && $video->getStatus() === VideoStatus::REFINING_EXTRACTION) {
                 $this->updateStatus($videoId, VideoStatus::REFINING_ANALYSIS);
            } elseif (!$isRefinement && $video->getStatus() === VideoStatus::SPLITTING) {
                 $this->updateStatus($videoId, VideoStatus::ANALYZING_FACES);
            }

            $currentScene = $this->entityManager->getRepository(VideoScene::class)
                ->createQueryBuilder('s')
                ->where('s.video = :video')
                ->andWhere(':ts >= s.startSeconds')
                ->andWhere(':ts < s.endSeconds')
                ->setParameter('video', $video)
                ->setParameter('ts', (float) $timestamp)
                ->setMaxResults(1) // Absolute Sicherheit
                ->getQuery()
                ->getOneOrNullResult();

            $allFacesData = $this->rekognitionService->processAllFacesInImage($framePath);
        } catch (\Throwable $e) {
            // In case of error, we still need to increment processedFrames so the pipeline can potentially finish
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE video SET processed_frames = processed_frames + 1 WHERE id = ?',
                [$videoId]
            );
            
            $this->logger->error('Error during frame analysis (Stage 1)', [
                'videoId' => $videoId,
                'error' => $e->getMessage()
            ]);

            $this->updateStatus($videoId, VideoStatus::ERROR, null, 'Frame analysis error: ' . $e->getMessage());
            return;
        }

        try {
            if (!empty($allFacesData)) {
                if (!file_exists($framePath)) {
                    throw new \RuntimeException("Frame file disappeared before Amazon Rekognition call: $framePath");
                }
                $imageContent = file_get_contents($framePath);
                $uuid = Uuid::uuid4()->toString();
                // Wir speichern im Root des faces.storage, was public/uploads/faces entspricht.
                // Innerhalb dieses Storages legen wir den Unterordner video_faces an.
                $storagePath = "video_faces/{$uuid}.jpg";
                $this->filesystem->write($storagePath, $imageContent);

                foreach ($allFacesData as $faceData) {
                    $this->saveFaceData($video, $faceData, $timestamp, $storagePath, $currentScene);
                }
            }

            // Atomic increment to avoid race conditions in multi-worker environments
            // JETZT ERST INKREMENTIEREN, wenn die Datei verarbeitet wurde!
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE video SET processed_frames = processed_frames + 1 WHERE id = ?',
                [$videoId]
            );
            $this->entityManager->refresh($video);

            // Sicherstellen, dass processedFrames nicht über totalFrames hinausgeht
            if ($video->getTotalFrames() > 0 && $video->getProcessedFrames() > $video->getTotalFrames()) {
                $video->setProcessedFrames($video->getTotalFrames());
                $this->entityManager->flush();
            }

            // Wenn es das letzte Frame war: FINALE!
            if ($isLastFrame) {
                $this->logger->info('Last frame message reached, checking for completion', [
                    'videoId' => $videoId,
                    'isRefinement' => $isRefinement,
                    'processed' => $video->getProcessedFrames(),
                    'total' => $video->getTotalFrames()
                ]);

                // WICHTIG: Wir dürfen nur abschließen, wenn wirklich alle Nachrichten verarbeitet wurden.
                // Da isLastFrame nur aussagt, DASS es die letzte Nachricht war, aber nicht ob sie als LETZTE ankommt.
                if ($video->getProcessedFrames() >= $video->getTotalFrames()) {
                    if ($isRefinement) {
                        $this->logger->info('Refinement step finished', ['videoId' => $videoId]);
                        $this->updateStatus($videoId, VideoStatus::MERGING_SCENES);
                        $this->mergeEmptyScenes($video);
                    } else {
                        $isRefiningStarted = $this->refineSceneAnalysis($video);

                        // Nur wenn KEINE Verfeinerung gestartet wurde, setzen wir auf MERGING_SCENES
                        if (!$isRefiningStarted) {
                            $this->logger->info('No refinement needed or possible, merging scenes', ['videoId' => $videoId]);
                            $this->updateStatus($videoId, VideoStatus::MERGING_SCENES);
                            $this->mergeEmptyScenes($video);
                        } else {
                            $this->logger->info('Refinement process started', ['videoId' => $videoId]);
                            // Status wurde bereits in extractFrames auf REFINING_EXTRACTION gesetzt
                        }
                    }
                } else {
                    $this->logger->info('Last frame reached but not all frames processed yet', [
                        'videoId' => $videoId,
                        'processed' => $video->getProcessedFrames(),
                        'total' => $video->getTotalFrames()
                    ]);
                }
            } else {
                // Auch wenn es NICHT das letzte Frame war, könnte es sein, dass die "isLastFrame"-Nachricht 
                // bereits verarbeitet wurde, aber noch andere Nachrichten ausstanden.
                if ($video->getProcessedFrames() >= $video->getTotalFrames()) {
                    // Wir müssen prüfen, ob wir im Status ANALYZING_FACES oder REFINING_ANALYSIS hängen
                    if ($video->getStatus() === VideoStatus::ANALYZING_FACES || $video->getStatus() === VideoStatus::REFINING_ANALYSIS) {
                        $this->logger->info('All frames processed but isLastFrame was already handled or not reached yet', [
                            'videoId' => $videoId,
                            'status' => $video->getStatus()->value
                        ]);
                        
                        // Hier rufen wir die Logik erneut auf, um sicherzustellen, dass wir nicht hängen bleiben
                        if ($video->getStatus() === VideoStatus::REFINING_ANALYSIS) {
                            $this->logger->info('Finalizing refinement from out-of-order message', ['videoId' => $videoId]);
                            $this->updateStatus($videoId, VideoStatus::MERGING_SCENES);
                            $this->mergeEmptyScenes($video);
                        } else {
                            $this->logger->info('Attempting to trigger refinement from out-of-order message', ['videoId' => $videoId]);
                            $isRefiningStarted = $this->refineSceneAnalysis($video);
                            if (!$isRefiningStarted) {
                                $this->updateStatus($videoId, VideoStatus::MERGING_SCENES);
                                $this->mergeEmptyScenes($video);
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // In case of error, we still need to increment processedFrames so the pipeline can potentially finish
            $video = $this->videoRepository->find($videoId);
            if ($video) {
                $this->entityManager->getConnection()->executeStatement(
                    'UPDATE video SET processed_frames = processed_frames + 1 WHERE id = ?',
                    [$videoId]
                );
            }

            $this->logger->error('Error during frame analysis (Stage 2)', [
                'videoId' => $videoId,
                'error' => $e->getMessage()
            ]);

            $this->updateStatus($videoId, VideoStatus::ERROR, null, 'Frame analysis error: ' . $e->getMessage());
        }
    }

    private function mergeEmptyScenes(Video $video): void
    {
        $this->logger->info('Merging empty scenes', ['videoId' => $video->getId()]);
        $scenes = $video->getScenes();
        if ($scenes->isEmpty()) {
            $this->updateStatus($video->getId(), VideoStatus::COMPLETED);
            return;
        }

        /** @var VideoScene[] $sceneArray */
        $sceneArray = $scenes->toArray();
        $lastSceneWithPerson = null;

        foreach ($sceneArray as $scene) {
            // Wir müssen die Collection neu laden oder sicherstellen, dass wir die aktuellen Faces haben
            $this->entityManager->refresh($scene);
            
            if (!$scene->getVideoFaces()->isEmpty()) {
                $lastSceneWithPerson = $scene;
                continue;
            }

            // Wenn es eine leere Szene ist und wir bereits eine Szene mit Person davor hatten
            if ($lastSceneWithPerson !== null) {
                $this->logger->info('Merging scene into last person scene', [
                    'videoId' => $video->getId(),
                    'emptyScene' => $scene->getSceneNumber(),
                    'targetScene' => $lastSceneWithPerson->getSceneNumber()
                ]);
                // Erweitere die letzte Szene mit Person bis zum Ende der aktuellen leeren Szene
                $lastSceneWithPerson->setEndSeconds($scene->getEndSeconds());
                
                // Entferne die leere Szene
                $video->removeScene($scene);
                $this->entityManager->remove($scene);
            }
        }

        $this->entityManager->flush();

        // Szenen neu nummerieren
        $remainingScenes = $video->getScenes();
        $counter = 1;
        foreach ($remainingScenes as $rs) {
            $rs->setSceneNumber($counter++);
        }
        $this->entityManager->flush();

        $this->updateStatus($video->getId(), VideoStatus::COMPLETED);
    }

    public function refineSceneAnalysis(Video $video): bool
    {
        if (!$video->getLocalPath()) {
            $this->logger->warning('Cannot refine analysis: No local path', ['videoId' => $video->getId()]);
            return false;
        }

        $minSceneLength = $video->getMinSceneLengthForRefinement() ?? $this->minSceneLengthForRefinement;
        $refinedFps = $video->getRefinedAnalysisFps() ?? $this->refinedFps;

        $scenesToRefine = [];
        foreach ($video->getScenes() as $scene) {
            $duration = $scene->getEndSeconds() - $scene->getStartSeconds();

            // Nur verfeinern, wenn keine Gesichter gefunden wurden und die Szene lang genug ist
            if ($scene->getVideoFaces()->isEmpty() && $duration >= $minSceneLength) {
                $scenesToRefine[] = $scene;
            }
        }

        if (empty($scenesToRefine)) {
            $this->logger->info('No scenes qualify for refinement', ['videoId' => $video->getId()]);
            return false;
        }

        $this->logger->info('Refining {count} scenes', [
            'videoId' => $video->getId(),
            'count' => count($scenesToRefine)
        ]);

        $startTimes = [];
        $durations = [];

        foreach ($scenesToRefine as $scene) {
            $startTimes[] = $scene->getStartSeconds();
            $durations[] = $scene->getEndSeconds() - $scene->getStartSeconds();
        }

        // Wir rufen extractFrames EINMAL für ALLE Szenen auf
        $this->extractFrames(
            $video->getId(),
            $video->getLocalPath(),
            $refinedFps,
            $startTimes,
            $durations,
            true, // markLastAsFinal
            true // isRefinement
        );

        return true;
    }

    public function cleanupLocalFile(int $videoId): void
    {
        $video = $this->videoRepository->find($videoId);
        if (!$video) {
            return;
        }

        $fs = new \Symfony\Component\Filesystem\Filesystem();

        // 1. Temporäre Frames löschen
        $dirs = [
            $video->getCurrentFrameDirectory(),
            $video->getCurrentRefinementFrameDirectory()
        ];

        foreach ($dirs as $frameDir) {
            if ($frameDir && is_dir($frameDir) && str_contains($frameDir, 'frames_')) {
                $fs->remove($frameDir);
            }
        }
        
        $video->setCurrentFrameDirectory(null);
        $video->setCurrentRefinementFrameDirectory(null);

        // 2. Video-Datei löschen
        $path = $video->getLocalPath();
        if ($path && file_exists($path)) {
            // Zusätzliche Sicherheit: Nur löschen, wenn es im Import-Ordner ODER im Temp-Ordner liegt
            $isImport = str_contains($path, '/uploads/import/');
            $isTemp = str_contains($path, sys_get_temp_dir());

            if ($isImport || $isTemp) {
                unlink($path);
                $video->setLocalPath(null);
            }
        }

        // 3. Konvertiertes Video löschen
        $convPath = $video->getConvertedVideoPath();
        if ($convPath && file_exists($convPath)) {
            $isImport = str_contains($convPath, '/uploads/import/');
            $isTemp = str_contains($convPath, sys_get_temp_dir());

            if ($isImport || $isTemp) {
                unlink($convPath);
                $video->setConvertedVideoPath(null);
            }
        }

        // 4. Thumbnail löschen
        $thumbnailPath = $video->getThumbnailPath();
        if ($thumbnailPath) {
            $cleanThumbnailPath = explode('?', $thumbnailPath)[0];
            $fullThumbnailPath = $this->projectDir . '/public/' . $cleanThumbnailPath;
            if (file_exists($fullThumbnailPath)) {
                @unlink($fullThumbnailPath);
            }
            $video->setThumbnailPath(null);
        }

        $this->entityManager->flush();
    }

    private function saveFaceData($video, $faceData, $timestamp, $storagePath, $currentScene): void
    {
        $this->entityManager->wrapInTransaction(
            function () use ($video, $faceData, $timestamp, $storagePath, $currentScene) {
                $person = null;
                $matchedFace = null;

                if (!empty($faceData['matchedFaceId'])) {
                    $matchedFace = $this->entityManager->getRepository(VideoFace::class)
                        ->findOneBy(['faceLabel' => $faceData['matchedFaceId']]);
                    $person = $matchedFace?->getPerson();
                }

                // OPTIMIERUNG: Wenn wir eine hohe Ähnlichkeit haben, verknüpfen wir es direkt mit der Person
                // Auch wenn wir keine matchedFaceId haben, könnten wir über FaceLabels suchen, 
                // aber Amazon gibt uns bei searchFaces bereits die beste Übereinstimmung.

                if ($person === null) {
                    // Falls wir die Person nicht über matchedFaceId finden, schauen wir, ob wir sie über den Namen finden (unknown_...)
                    // Das ist aber unzuverlässig. Besser: Neue Person anlegen.
                    $person = new Person();
                    $person->setName('unknown_' . substr($faceData['faceId'], 0, 8));
                    $person->setIdentified(false);
                    $this->entityManager->persist($person);
                    // Flush ist hier wichtig, damit die Person eine ID bekommt, falls wir sie später im Loop brauchen
                    // Aber wir sind in einem Loop in analyzeFrame.
                }

                $videoFace = new VideoFace();
                $videoFace->setVideo($video);
                $videoFace->setPerson($person);
                $videoFace->setTimestamp($timestamp);
                $videoFace->setFaceImagePath($storagePath);
                $videoFace->setFaceLabel($faceData['faceId']);
                $videoFace->setAge((int) $faceData['age']);
                $videoFace->setGender((string) $faceData['gender']);
                $videoFace->setEmotion((string) $faceData['emotion']);
                $videoFace->setBoundingBox($faceData['boundingBox']);

                if ($currentScene) {
                    $videoFace->setVideoScene($currentScene);
                }
                if ($matchedFace) {
                    $videoFace->setMatchedBy($matchedFace);
                    $videoFace->setMatchSimilarity((float) $faceData['similarity']);

                    // NEU: Wenn die Ähnlichkeit hoch genug ist, markieren wir die Person als "wahrscheinlich"
                    if ($faceData['similarity'] >= 80.0 && $matchedFace->getPerson() && $matchedFace->getPerson()->isIdentified()) {
                        $videoFace->setDetection($matchedFace->getPerson());
                    }
                }

                $this->entityManager->persist($videoFace);
            }
        );
    }
}