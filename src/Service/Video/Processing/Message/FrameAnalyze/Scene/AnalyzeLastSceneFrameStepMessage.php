<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\FrameAnalyze\Scene;

use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Message\FrameAnalyze\AbstractAnalyzeFrameStepMessage;
use App\Service\Video\Processing\Message\Refinement\InitRefinementForEmptyScenesStepMessage;

#[StepOrder(stepNumber: 16)]
class AnalyzeLastSceneFrameStepMessage extends AbstractAnalyzeFrameStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'ANALYZE LAST SCENE FRAME';

    public function __construct(int $videoId, int $messageNrInVideoStack, string $comingFromStepMessageClass)
    {
        $this->videoId = $videoId;
        $this->messageNrInVideoStack = $messageNrInVideoStack;
        $this->comingFromStepMessageClass = $comingFromStepMessageClass;
    }

    public function nextStepNeedsTransition(): bool
    {
        return true;
    }

    public function getTransitionToStatusNextStepIsExpecting(): VideoWorkflowProcessTransition
    {
        return VideoWorkflowProcessTransition::START_REFINING_EXTRACTION;
    }

    public function getNextStepMessageClass(): string
    {
    }
}
