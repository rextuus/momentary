<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;

readonly class VideoDurationEstimator
{
    public function __construct(private VideoRepository $videoRepository) {}

    public function estimateDuration(Video $video, VideoStatus $status): int
    {
        $averageSecondsPerVideoSecond = $this->getAverageSecondsPerVideoSecond($status);
        $videoDuration = $video->getDuration() ?? 60.0; // Fallback 60s
        
        return (int) ($videoDuration * $averageSecondsPerVideoSecond);
    }

    private function getAverageSecondsPerVideoSecond(VideoStatus $status): float
    {
        // Whitelist of statuses that are supported for duration estimation
        if (!in_array($status, [
            VideoStatus::CONVERTING, 
            VideoStatus::SCENE_DETECTION, 
            VideoStatus::VIDEO_SPLITTING,
            VideoStatus::ANALYZING_FACES
        ])) {
            return 1.0; // Fallback
        }

        // Suche alle fertig verarbeiteten Videos
        $videos = $this->videoRepository->findBy(['status' => VideoStatus::COMPLETED]);
        
        $totalSeconds = 0.0;
        $totalVideoSeconds = 0.0;
        $count = 0;

        foreach ($videos as $video) {
            $duration = null;
            foreach ($video->getProcessingSteps() as $step) {
                if ($step->getStep() === $status) {
                    $duration = $step->getDuration();
                    break;
                }
            }
            
            if ($duration !== null && $video->getDuration() !== null && $video->getDuration() > 0) {
                $totalSeconds += (float)$duration;
                $totalVideoSeconds += $video->getDuration();
                $count++;
            }
        }

        if ($count === 0 || $totalVideoSeconds === 0) {
            return 1.0; // Fallback
        }

        return $totalSeconds / $totalVideoSeconds;
    }
}
