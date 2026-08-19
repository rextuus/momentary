<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Repository\VideoProcessingStepRepository;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class VideoProgressBar
{
    use DefaultActionTrait;

    #[LiveProp]
    public Video $video;

    public function __construct(
        private VideoRepository $videoRepository,
        private VideoProcessingStepRepository $stepRepository
    ) {}

    public function getVideo(): Video
    {
        // Refresh the video entity to get the latest progress
        return $this->videoRepository->find($this->video->getId());
    }

    public function getEstimatedDuration(): ?int
    {
        $status = $this->getVideo()->getStatus();
        return $this->stepRepository->getAverageDurationForStep($status);
    }

    public function getPercentage(): int
    {
        $video = $this->getVideo();
        $status = $video->getStatus();

        if ($status === VideoStatus::COMPLETED) {
            return 100;
        }

        if (in_array($status, [VideoStatus::ANALYZING_FACES_INITIAL, VideoStatus::ANALYZING_FACES_REFINEMENT])) {
            if ($video->getTotalFrames() <= 0) {
                return 0;
            }
            return (int) min(99, round(($video->getProcessedFrames() / $video->getTotalFrames()) * 100));
        }

        if ($status === VideoStatus::EXTRACTING_THUMBNAILS) {
            $totalScenes = $video->getScenes()->count();
            if ($totalScenes === 0) {
                return 0;
            }
            $scenesWithThumbnails = 0;
            foreach ($video->getScenes() as $scene) {
                if ($scene->getThumbnailUrl() !== null) {
                    $scenesWithThumbnails++;
                }
            }
            return (int) min(99, round(($scenesWithThumbnails / $totalScenes) * 100));
        }

        if ($status === VideoStatus::TAGGING_SCENES) {
            $totalScenes = $video->getScenes()->count();
            if ($totalScenes === 0) {
                return 0;
            }
            $scenesWithTags = 0;
            foreach ($video->getScenes() as $scene) {
                if ($scene->getTags()->count() > 0) {
                    $scenesWithTags++;
                }
            }
            return (int) min(99, round(($scenesWithTags / $totalScenes) * 100));
        }

        // Mapping aller Status auf Prozentwerte für die ProgressBar
        $statusOrder = [
            VideoStatus::PENDING->value => 0,
            VideoStatus::CONVERTING->value => 10,
            VideoStatus::SCENE_DETECTION->value => 20,
            VideoStatus::EXTRACTING_THUMBNAILS->value => 25,
            VideoStatus::VIDEO_SPLITTING->value => 30,
            VideoStatus::ANALYZING_FACES_INITIAL->value => 40,
            VideoStatus::REFINING_EXTRACTION->value => 60,
            VideoStatus::REFINING_SPLITTING->value => 70,
            VideoStatus::ANALYZING_FACES_REFINEMENT->value => 80,
            VideoStatus::MERGING_SCENES->value => 90,
            VideoStatus::TAGGING_SCENES->value => 93,
            VideoStatus::CHAPTER_GENERATION->value => 96,
            VideoStatus::EXPORTING_JELLYFIN->value => 98,
            VideoStatus::COMPLETED->value => 100,
        ];

        return $statusOrder[$status->value] ?? ($status === VideoStatus::ERROR ? 0 : 0);
    }

    public function isProcessing(): bool
    {
        $status = $this->getVideo()->getStatus();
        return !in_array($status, [VideoStatus::COMPLETED, VideoStatus::ERROR, VideoStatus::PENDING]);
    }
}