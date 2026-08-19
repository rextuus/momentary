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
class VideoPipelineComponent
{
    use DefaultActionTrait;

    #[LiveProp]
    public Video $video;

    public function __construct(
        private VideoRepository $videoRepository,
        private ?\App\Service\VideoDurationEstimator $estimator = null
    ) {}

    public function getEstimatedProgress(string $statusName): int
    {
        $video = $this->getVideo();
        $status = VideoStatus::tryFrom($statusName);
        if (!$status || !$this->estimator) {
            return 0;
        }

        $estimatedDuration = $this->estimator->estimateDuration($video, $status);
        if ($estimatedDuration <= 0) {
            return 0;
        }

        $startedAt = null;
        foreach ($video->getProcessingSteps() as $step) {
            if ($step->getStep() === $status) {
                $startedAt = $step->getStartedAt();
                break;
            }
        }

        if (!$startedAt) {
            return 0;
        }

        $elapsed = time() - $startedAt->getTimestamp();
        $progress = (int) (($elapsed / $estimatedDuration) * 90);

        return min(90, max(0, $progress));
    }

    public function getVideo(): Video
    {
        return $this->videoRepository->find($this->video->getId()) ?? $this->video;
    }

    public function isProcessing(): bool
    {
        $status = $this->getVideo()->getStatus();
        return $status !== VideoStatus::COMPLETED && $status !== VideoStatus::ERROR;
    }
}