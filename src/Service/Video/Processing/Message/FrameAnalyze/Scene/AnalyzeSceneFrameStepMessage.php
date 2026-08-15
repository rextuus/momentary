<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\FrameAnalyze\Scene;

use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Message\FrameAnalyze\AbstractAnalyzeFrameStepMessage;
use App\Service\Video\Processing\Message\FrameAnalyze\Video\AnalyzeLastVideoFrameStepMessage;

#[StepOrder(stepNumber: 15)]
class AnalyzeSceneFrameStepMessage extends AbstractAnalyzeFrameStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'ANALYZE SCENE FRAME';

    public function __construct(int $videoId, int $messageNrInVideoStack, string $comingFromStepMessageClass)
    {
        $this->videoId = $videoId;
        $this->messageNrInVideoStack = $messageNrInVideoStack;
        $this->comingFromStepMessageClass = $comingFromStepMessageClass;
    }

    public function getNextStepMessageClass(): string
    {
        return AnalyzeLastSceneFrameStepMessage::class;
    }

    public function isIntermediateStep(): bool
    {
        return true;
    }
}
