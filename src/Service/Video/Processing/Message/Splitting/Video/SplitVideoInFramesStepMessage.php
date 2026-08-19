<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\Splitting\Video;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Message\FrameAnalyze\Video\AnalyzeFirstVideoFrameStepMessage;
use App\Service\Video\Processing\Message\Splitting\AbstractSplitInFramesStepMessage;

#[StepOrder(stepNumber: 6)]
class SplitVideoInFramesStepMessage extends AbstractSplitInFramesStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'SPLITTING ENTIRE VIDEO INTO FRAMES';

    public function __construct(int $videoId, int $messageNrInVideoStack, string $comingFromStepMessageClass)
    {
        $this->videoId = $videoId;
        $this->messageNrInVideoStack = $messageNrInVideoStack;
        $this->comingFromStepMessageClass = $comingFromStepMessageClass;
    }

    public function getTransitionToStatusNextStepIsBelonging(): VideoWorkflowProcessTransition
    {
        return VideoWorkflowProcessTransition::START_ANALYZING;
    }

    public function getNextStepMessageClass(): ?string
    {
        return AnalyzeFirstVideoFrameStepMessage::class;
    }

    public function getVideoStatusForCurrentProcessStepEntity(): VideoStatus
    {
        return VideoStatus::VIDEO_SPLITTING;
    }

    public function nextStepNeedsTransition(): bool
    {
        return true;
    }
}
