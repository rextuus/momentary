<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\Refinement;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Message\Abstract\AbstractVideoProcessStepMessage;
use App\Service\Video\Processing\Message\Splitting\Scene\SplitFirstSceneInFramesStepMessage;

#[StepOrder(stepNumber: 10)]
class InitRefinementForEmptyScenesStepMessage extends AbstractVideoProcessStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'INIT REFINEMENT FOR EMPTY SCENES';

    public function __construct(int $videoId, int $messageNrInVideoStack, string $comingFromStepMessageClass)
    {
        $this->videoId = $videoId;
        $this->messageNrInVideoStack = $messageNrInVideoStack;
        $this->comingFromStepMessageClass = $comingFromStepMessageClass;
    }

    public function getNextStepMessageClass(): string
    {
        return SplitFirstSceneInFramesStepMessage::class;
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
