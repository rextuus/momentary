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
use App\Service\Aws\AmazonRekognitionService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Process;

readonly class VideoAnalyzer
{
    public function __construct(
        private MessageBusInterface $bus,
        private FilesystemOperator $filesystem,
        private EntityManagerInterface $entityManager,
        private VideoRepository $videoRepository,
        private AmazonRekognitionService $rekognitionService,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire('%env(PYTHON_BINARY)%')]
        private string $pythonBinary = '/usr/bin/python3'
    ) {
    }

    private function updateStatus(int $videoId, VideoStatus $status, ?string $localPath = null): void
    {
        $video = $this->videoRepository->find($videoId);
        if ($video) {
            $video->setStatus($status);
            if ($localPath) {
                $video->setLocalPath($localPath);
            }
            $this->entityManager->flush();
        }
    }

    public function downloadVideo(int $videoId, string $youtubeUrl): ?string
    {
        $this->updateStatus($videoId, VideoStatus::DOWNLOADING);

        $process = new Process([
            $this->pythonBinary,
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

            $this->updateStatus($videoId, VideoStatus::ERROR);
            throw new \RuntimeException($msg);
        }

        $data = json_decode($process->getOutput(), true);
        $path = $data['video_path'] ?? null;

        // Nach dem Download speichern wir den Pfad direkt am Video
        $this->updateStatus($videoId, VideoStatus::PENDING, $path);

        return $path;
    }

    public function detectScenes(string $videoPath, int $videoId, float $threshold = 27.0, string $detector = 'content'): array
    {
        $video = $this->entityManager->find(Video::class, $videoId);

        // FIX: Alte Szenen löschen, bevor wir neue erkennen
        $this->clearOldScenes($video);

        $this->updateStatus($videoId, VideoStatus::SCENE_DETECTION);

        $process = new Process([
            $this->pythonBinary,
            $this->projectDir . '/video-analyzer/python/detect_scenes.py',
            $videoPath,
            '--threshold',
            (string)$threshold,
            '--detector',
            $detector
        ]);

        $process->setTimeout(900);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->updateStatus($videoId, VideoStatus::ERROR);
            throw new \RuntimeException('Scene detection failed: ' . $process->getErrorOutput());
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
            throw new \RuntimeException('Failed to decode scene detection output: ' . json_last_error_msg() . ' | Raw output: ' . $output);
        }

        // Falls Warnungen im JSON enthalten sind, diese loggen/ausgeben
        if (count($data) === 1 && isset($data[0]['warning'])) {
            // Wenn nur eine Szene mit Warnung zurückkommt, deutet das auf technische Probleme hin
            // (z.B. Interlaced MPG). In diesem Fall versuchen wir eine automatische Konvertierung
            // und starten den Prozess erneut, falls es noch nicht die konvertierte Version war.
            if (!str_contains($videoPath, 'video_analyze_')) {
                 $tempMp4 = sys_get_temp_dir() . '/video_analyze_' . $videoId . '.mp4';
                 
                 // Konvertierung
                 $convProcess = new Process([
                     'ffmpeg', '-y', '-i', $videoPath, 
                     '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '23', 
                     '-c:a', 'aac', $tempMp4
                 ]);
                 $convProcess->setTimeout(1800);
                 $convProcess->run();

                 if ($convProcess->isSuccessful()) {
                     // Wir nutzen den konvertierten Pfad für den Rest der Pipeline
                     $video->setLocalPath($tempMp4);
                     $this->entityManager->flush();

                     $retryData = $this->detectScenes($tempMp4, $videoId, $threshold, $detector);
                     return $retryData;
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

    public function extractFrames(int $videoId, string $videoPath, float $fps = 0.2): bool
    {
        $this->updateStatus($videoId, VideoStatus::SPLITTING);

        try {
            $process = new Process([
                $this->pythonBinary,
                $this->projectDir . '/video-analyzer/python/extract_frames.py',
                $videoPath,
                (string)$fps
            ]);

            $process->setTimeout(600);
            $process->run();

            if (!$process->isSuccessful()) {
                $this->updateStatus($videoId, VideoStatus::ERROR);
                return false;
            }

            $frameList = json_decode($process->getOutput(), true);
            foreach ($frameList as $index => $frame) {
                $this->bus->dispatch(
                    new FrameAnalyzerMessage(
                        $videoId,
                        $frame['path'],
                        (int) $frame['timestamp'],
                        $index === array_key_last($frameList) // Letztes Frame markieren
                    )
                );
            }

            return true;
        } catch (\Throwable $e) {
            $this->updateStatus($videoId, VideoStatus::ERROR);
            return false;
        }
    }

    private function clearOldScenes(Video $video): void
    {
        $connection = $this->entityManager->getConnection();

        // 1. Alle Faces löschen, die an Szenen dieses Videos hängen
        // (Falls deine Relationen kein cascade-delete haben)
        $connection->executeStatement(
            'DELETE FROM video_face WHERE video_id = ?',
            [$video->getId()]
        );

        // 2. Alle Szenen des Videos löschen
        $connection->executeStatement(
            'DELETE FROM video_scene WHERE video_id = ?',
            [$video->getId()]
        );

        $this->entityManager->refresh($video);
    }

    public function analyzeFrame(int $videoId, string $framePath, int $timestamp, bool $isLastFrame): void
    {
        // Wir setzen den Status nur beim ersten Frame auf "Analyzing"
        $this->updateStatus($videoId, VideoStatus::ANALYZING_FACES);

        $video = $this->videoRepository->find($videoId);
        if (!$video) {
            return;
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

        if (!empty($allFacesData)) {
            $imageContent = file_get_contents($framePath);
            $uuid = Uuid::uuid4()->toString();
            $storagePath = "video_faces/{$uuid}.jpg";
            $this->filesystem->write($storagePath, $imageContent);

            foreach ($allFacesData as $faceData) {
                $this->saveFaceData($video, $faceData, $timestamp, $storagePath, $currentScene);
            }
        }

        // Wenn es das letzte Frame war: FINALE!
        if ($isLastFrame) {
            $this->updateStatus($videoId, VideoStatus::COMPLETED);
            $this->cleanupLocalFile($videoId);
        }
    }

    public function cleanupLocalFile(int $videoId): void
    {
        $video = $this->videoRepository->find($videoId);
        if (!$video) {
            return;
        }

        // Wir löschen die Datei nur, wenn:
        // 1. Eine YouTube-URL vorhanden ist (Sicherheit, dass es online ist)
        // 2. Ein lokaler Pfad gesetzt ist
        if ($video->getYoutubeUrl() && $video->getLocalPath()) {
            $path = $video->getLocalPath();
            if (file_exists($path)) {
                // Zusätzliche Sicherheit: Nur löschen, wenn es im Import-Ordner ODER im Temp-Ordner liegt
                $isImport = str_contains($path, '/uploads/import/');
                $isTemp = str_contains($path, sys_get_temp_dir());

                if ($isImport || $isTemp) {
                    unlink($path);
                }
            }
        }
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

                if ($person === null) {
                    $person = new Person();
                    $person->setName('unknown_' . substr($faceData['faceId'], 0, 8));
                    $person->setIdentified(false);
                    $this->entityManager->persist($person);
                    $this->entityManager->flush();
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
                }

                $this->entityManager->persist($videoFace);
            }
        );
    }
}