<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use App\Entity\Video;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

class SceneDetector
{
    private string $pythonBinaryPath;

    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
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

    public function detectScenes(
        string $videoPath,
        int $videoId,
        float $threshold = 27.0,
        string $detector = 'content'
    ): array {
        $video = $this->entityManager->find(Video::class, $videoId);

        // FIX: Alte Szenen löschen, bevor wir neue erkennen
        $this->clearOldScenes($video);

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
}
