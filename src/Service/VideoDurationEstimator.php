<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;

class VideoDurationEstimator
{
    public function __construct(private VideoRepository $videoRepository) {}

    public function estimateDuration(Video $video, VideoStatus $status): int
    {
        // Wenn bereits eine Schätzung existiert, nutze diese (aber überschreibe sie ggf. wenn wir eine neue berechnen)
        // Eigentlich wollen wir aber einen Durchschnitt berechnen.
        
        $averageSecondsPerVideoSecond = $this->getAverageSecondsPerVideoSecond($status);
        $videoDuration = $video->getDuration() ?? 60.0; // Fallback 60s
        
        return (int) ($videoDuration * $averageSecondsPerVideoSecond);
    }

    private function getAverageSecondsPerVideoSecond(VideoStatus $status): float
    {
        $durationField = $this->getDurationFieldForStatus($status);
        if (!$durationField) {
            return 1.0; // Fallback
        }

        // Suche alle fertig verarbeiteten Videos
        $videos = $this->videoRepository->findBy(['status' => VideoStatus::COMPLETED]);
        
        $totalSeconds = 0.0;
        $totalVideoSeconds = 0.0;
        $count = 0;

        foreach ($videos as $video) {
            $getter = 'get' . ucfirst($durationField);
            $duration = $video->$getter();
            
            if ($duration !== null && $video->getDuration() !== null && $video->getDuration() > 0) {
                $totalSeconds += $duration;
                $totalVideoSeconds += $video->getDuration();
                $count++;
            }
        }

        if ($count === 0 || $totalVideoSeconds === 0) {
            return 1.0; // Fallback
        }

        return $totalSeconds / $totalVideoSeconds;
    }

    private function getDurationFieldForStatus(VideoStatus $status): ?string
    {
        return match ($status) {
            VideoStatus::CONVERTING => 'conversionDuration',
            VideoStatus::SCENE_DETECTION => 'sceneDetectionDuration',
            VideoStatus::SPLITTING => 'frameExtractionDuration',
            VideoStatus::ANALYZING_FACES => 'faceAnalysisDuration',
            default => null,
        };
    }
}
