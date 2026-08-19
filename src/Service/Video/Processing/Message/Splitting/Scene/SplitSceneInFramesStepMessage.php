<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\Splitting\Scene;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Message\Splitting\AbstractSplitInFramesStepMessage;

#[StepOrder(stepNumber: 12)]
class SplitSceneInFramesStepMessage extends AbstractSplitInFramesStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'SPLIT SCENE IN FRAMES';

    public function __construct(int $videoId, int $messageNrInVideoStack, string $comingFromStepMessageClass)
    {
        $this->videoId = $videoId;
        $this->messageNrInVideoStack = $messageNrInVideoStack;
        $this->comingFromStepMessageClass = $comingFromStepMessageClass;
    }

    public function getNextStepMessageClass(): ?string
    {
        return SplitLastSceneInFramesStepMessage::class;
    }

    public function getVideoStatusForCurrentProcessStepEntity(): VideoStatus
    {
        return VideoStatus::REFINING_SPLITTING;
    }

    public function isIntermediateStep(): bool
    {
        return true;
    }
}
