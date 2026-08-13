<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\FrameAnalyze;

use App\Service\Video\Processing\Attribute\StepOrder;

#[StepOrder(stepNumber: 7)]
class AnalyzeFirstFrameStepMessage extends AbstractAnalyzeFrameStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'ANALYZE FIRST FRAME';

    public function __construct(int $videoId)
    {
        $this->videoId = $videoId;
    }

    public function getNextStepMessageClass(): string
    {
        return AnalyzeFrameStepMessage::class;
    }
}
