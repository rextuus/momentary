<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\Splitting\Video;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Message\FrameAnalyze\AnalyzeFirstFrameStepMessage;
use App\Service\Video\Processing\Message\Splitting\AbstractSplitInFramesStepMessage;

#[StepOrder(stepNumber: 6)]
class SplitVideoInFramesStepMessage extends AbstractSplitInFramesStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'SPLITTING ENTIRE VIDEO INTO FRAMES';

    public function __construct(int $videoId)
    {
        $this->videoId = $videoId;
    }

    public function getTransitionToStatusNextStepIsExpecting(): VideoWorkflowProcessTransition
    {
        return VideoWorkflowProcessTransition::START_ANALYZING;
    }

    public function getNextStepMessageClass(): string
    {
        return AnalyzeFirstFrameStepMessage::class;
    }

    public function getVideoStatusForCurrentProcessStepEntity(): VideoStatus
    {
        return VideoStatus::SPLITTING;
    }

    public function nextStepNeedsTransition(): bool
    {
        return true;
    }
}
