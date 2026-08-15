<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Message\Abstract\AbstractVideoProcessStepMessage;
use App\Service\Video\Processing\Message\ThumbnailExtraction\ExtractFirstSceneThumbnailStepMessage;

#[StepOrder(stepNumber: 2)]
class SceneDetectionStepMessage extends AbstractVideoProcessStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'SCENE DETECTION';

    public function __construct(int $videoId, int $messageNrInVideoStack, string $comingFromStepMessageClass)
    {
        $this->videoId = $videoId;
        $this->messageNrInVideoStack = $messageNrInVideoStack;
        $this->comingFromStepMessageClass = $comingFromStepMessageClass;
    }

    public function getTransitionToStatusNextStepIsBelonging(): VideoWorkflowProcessTransition
    {
        return VideoWorkflowProcessTransition::START_SCENE_DETECTION;
    }

    public function getNextStepMessageClass(): string
    {
        return ExtractFirstSceneThumbnailStepMessage::class;
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
