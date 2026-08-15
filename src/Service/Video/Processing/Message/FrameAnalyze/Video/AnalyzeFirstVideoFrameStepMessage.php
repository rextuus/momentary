<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\FrameAnalyze\Video;

use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Message\FrameAnalyze\AbstractAnalyzeFrameStepMessage;

#[StepOrder(stepNumber: 7)]
class AnalyzeFirstVideoFrameStepMessage extends AbstractAnalyzeFrameStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'ANALYZE FIRST VIDEO FRAME';

    public function __construct(int $videoId, int $messageNrInVideoStack, string $comingFromStepMessageClass)
    {
        $this->videoId = $videoId;
        $this->messageNrInVideoStack = $messageNrInVideoStack;
        $this->comingFromStepMessageClass = $comingFromStepMessageClass;
    }

    public function getNextStepMessageClass(): string
    {
        return AnalyzeVideoFrameStepMessage::class;
    }
}
