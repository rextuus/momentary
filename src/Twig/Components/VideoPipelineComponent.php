<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Video;
use App\Repository\VideoRepository;
use App\Service\Video\Progress\VideoProgress;
use App\Service\Video\Progress\VideoProgressCalculator;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class VideoPipelineComponent
{
    use DefaultActionTrait;

    #[LiveProp]
    public Video $video;

    private ?VideoProgress $progress = null;

    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly VideoProgressCalculator $calculator,
    ) {}

    public function getVideo(): Video
    {
        return $this->videoRepository->find($this->video->getId()) ?? $this->video;
    }

    public function getProgress(): VideoProgress
    {
        return $this->progress ??= $this->calculator->calculate($this->getVideo());
    }

    public function isProcessing(): bool
    {
        return $this->getVideo()->getStatus()->isProcessing();
    }
}
