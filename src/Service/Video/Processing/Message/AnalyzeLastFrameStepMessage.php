<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Exception\StepMessageTransitionException;
use App\Service\Video\Processing\Message\Abstract\AbstractAnalyzeFrameStepMessage;

#[StepOrder(stepNumber: 9)]
class AnalyzeLastFrameStepMessage extends AbstractAnalyzeFrameStepMessage
{
    public function __construct(int $videoId)
    {
        $this->videoId = $videoId;
    }

    public function nextStepNeedsTransition(): bool
    {
        return true;
    }

    public function getTransitionToStatusNextStepIsExpecting(): VideoWorkflowProcessTransition
    {

    }

    public function getNextStepMessageClass(): string
    {
    }
}
