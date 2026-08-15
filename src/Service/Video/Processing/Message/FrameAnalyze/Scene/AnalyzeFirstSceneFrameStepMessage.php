<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\FrameAnalyze\Scene;

use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Message\FrameAnalyze\AbstractAnalyzeFrameStepMessage;
use App\Service\Video\Processing\Message\FrameAnalyze\Video\AnalyzeVideoFrameStepMessage;

#[StepOrder(stepNumber: 14)]
class AnalyzeFirstSceneFrameStepMessage extends AbstractAnalyzeFrameStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'ANALYZE FIRST SCENE FRAME';

    public function __construct(int $videoId, int $messageNrInVideoStack, string $comingFromStepMessageClass)
    {
        $this->videoId = $videoId;
        $this->messageNrInVideoStack = $messageNrInVideoStack;
        $this->comingFromStepMessageClass = $comingFromStepMessageClass;
    }

    public function getNextStepMessageClass(): string
    {
        return AnalyzeSceneFrameStepMessage::class;
    }
}
