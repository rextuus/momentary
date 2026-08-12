<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message;

use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Message\Abstract\AbstractExtractSceneThumbnailStepMessage;

#[StepOrder(stepNumber: 5)]
class ExtractLastSceneThumbnailStepMessage extends AbstractExtractSceneThumbnailStepMessage
{
    public function __construct(int $videoId)
    {
        $this->videoId = $videoId;
    }

    public function getTransitionToStatusNextStepIsExpecting(): VideoWorkflowProcessTransition
    {
        return VideoWorkflowProcessTransition::START_SPLITTING;
    }

    public function getNextStepMessageClass(): string
    {
        return SplitInFramesStepMessage::class;
    }

    public function nextStepNeedsTransition(): bool
    {
        return true;
    }
}
