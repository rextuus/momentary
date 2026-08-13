<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Message\Abstract\AbstractVideoProcessStepMessage;

#[StepOrder(stepNumber: 1)]
class ConvertStepMessage extends AbstractVideoProcessStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'CONVERSION TO MP4';

    public function __construct(int $videoId)
    {
        $this->videoId = $videoId;
    }

    public function getTransitionToStatusNextStepIsExpecting(): VideoWorkflowProcessTransition
    {
        return VideoWorkflowProcessTransition::START_SCENE_DETECTION;
    }

    public function getNextStepMessageClass(): string
    {
        return SceneDetectionStepMessage::class;
    }

    public function getVideoStatusForCurrentProcessStepEntity(): VideoStatus
    {
        return VideoStatus::CONVERTING;
    }

    public function nextStepNeedsTransition(): bool
    {
        return true;
    }
}
