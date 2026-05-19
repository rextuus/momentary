<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class VideoProgressBar
{
    use DefaultActionTrait;

    #[LiveProp]
    public Video $video;

    public function __construct(private VideoRepository $videoRepository) {}

    public function getVideo(): Video
    {
        // Refresh the video entity to get the latest progress
        return $this->videoRepository->find($this->video->getId());
    }

    public function getPercentage(): int
    {
        $video = $this->getVideo();
        $status = $video->getStatus();

        if ($status === VideoStatus::COMPLETED) {
            return 100;
        }

        if (in_array($status, [VideoStatus::ANALYZING_FACES, VideoStatus::REFINING_ANALYSIS])) {
            if ($video->getTotalFrames() <= 0) {
                return 0;
            }
            return (int) min(99, round(($video->getProcessedFrames() / $video->getTotalFrames()) * 100));
        }

        // Andere statustypen haben keine Frame-basierte Prozentrechnung
        $statusOrder = [
            VideoStatus::PENDING->value => 0,
            VideoStatus::DOWNLOADING->value => 5,
            VideoStatus::CONVERTING->value => 10,
            VideoStatus::SCENE_DETECTION->value => 20,
            VideoStatus::SPLITTING->value => 30,
            VideoStatus::ANALYZING_FACES->value => 40,
            VideoStatus::REFINING_EXTRACTION->value => 70,
            VideoStatus::REFINING_ANALYSIS->value => 80,
            VideoStatus::MERGING_SCENES->value => 95,
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
