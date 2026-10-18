<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Message\Abstract\AbstractVideoProcessStepMessage;

/**
 * Letzter Pipeline-Schritt: Ton transkribieren. Wird über die Queue "transcription" vom transcription-worker
 * verarbeitet, damit die stundenlange CPU-Arbeit die Pipeline anderer Videos nicht aufhält.
 */
#[StepOrder(stepNumber: 25)]
class TranscribeStepMessage extends AbstractVideoProcessStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'TRANSCRIPTION';

    public function __construct(int $videoId, int $messageNrInVideoStack, string $comingFromStepMessageClass)
    {
        $this->videoId = $videoId;
        $this->messageNrInVideoStack = $messageNrInVideoStack;
        $this->comingFromStepMessageClass = $comingFromStepMessageClass;
    }

    public function getTransitionToStatusNextStepIsBelonging(): VideoWorkflowProcessTransition
    {
        return VideoWorkflowProcessTransition::COMPLETE;
    }

    public function getNextStepMessageClass(): ?string
    {
        return null;
    }

    public function getVideoStatusForCurrentProcessStepEntity(): VideoStatus
    {
        return VideoStatus::TRANSCRIBING;
    }

    public function nextStepNeedsTransition(): bool
    {
        return true;
    }
}
