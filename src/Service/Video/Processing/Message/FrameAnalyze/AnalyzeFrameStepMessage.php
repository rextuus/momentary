<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\FrameAnalyze;

use App\Service\Video\Processing\Attribute\StepOrder;

#[StepOrder(stepNumber: 8)]
class AnalyzeFrameStepMessage extends AbstractAnalyzeFrameStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'ANALYZE FRAME';

    public function __construct(int $videoId)
    {
        $this->videoId = $videoId;
    }

    public function getNextStepMessageClass(): string
    {
        return AnalyzeLastFrameStepMessage::class;
    }
}
