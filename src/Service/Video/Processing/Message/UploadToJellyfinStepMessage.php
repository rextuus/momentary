<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Message\Abstract\AbstractVideoProcessStepMessage;

#[StepOrder(stepNumber: 24)]
class UploadToJellyfinStepMessage extends AbstractVideoProcessStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'UPLOAD TO JELLYFIN';

    public function __construct(int $videoId, int $messageNrInVideoStack, string $comingFromStepMessageClass)
    {
        $this->videoId = $videoId;
        $this->messageNrInVideoStack = $messageNrInVideoStack;
        $this->comingFromStepMessageClass = $comingFromStepMessageClass;
    }

    public function getTransitionToStatusNextStepIsBelonging(): VideoWorkflowProcessTransition
    {
        return VideoWorkflowProcessTransition::START_TRANSCRIPTION;
    }

    public function getNextStepMessageClass(): ?string
    {
        return TranscribeStepMessage::class;
    }

    public function getVideoStatusForCurrentProcessStepEntity(): VideoStatus
    {
        return VideoStatus::EXPORTING_JELLYFIN;
    }

    public function nextStepNeedsTransition(): bool
    {
        return true;
    }
}
