<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\Splitting\Scene;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Message\Splitting\AbstractSplitInFramesStepMessage;

#[StepOrder(stepNumber: 12)]
class SplitSceneInFramesStepMessage extends AbstractSplitInFramesStepMessage
{
    public function __construct(int $videoId)
    {
        $this->videoId = $videoId;
    }

    public function getNextStepMessageClass(): string
    {
        return SplitLastSceneInFramesStepMessage::class;
    }

    public function getVideoStatusForCurrentProcessStepEntity(): VideoStatus
    {
        return VideoStatus::REFINING_EXTRACTION;
    }
}
