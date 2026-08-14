<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\Splitting\Scene;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Message\Splitting\AbstractSplitInFramesStepMessage;

#[StepOrder(stepNumber: 11)]
class SplitFirstSceneInFramesStepMessage extends AbstractSplitInFramesStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'SPLIT_FIRST_SCENE_IN_FRAMES';

    public function __construct(int $videoId)
    {
        $this->videoId = $videoId;
    }

    public function getNextStepMessageClass(): string
    {
        return SplitSceneInFramesStepMessage::class;
    }

    public function getVideoStatusForCurrentProcessStepEntity(): VideoStatus
    {
        return VideoStatus::REFINING_EXTRACTION;
    }

    public function getTransitionToStatusNextStepIsExpecting(): VideoWorkflowProcessTransition
    {
        return VideoWorkflowProcessTransition::START_REFINING_SPLITTING;
    }

    public function nextStepNeedsTransition(): bool
    {
        return true;
    }
}
