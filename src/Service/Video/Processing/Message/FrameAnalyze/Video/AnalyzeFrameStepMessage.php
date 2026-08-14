<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\FrameAnalyze\Video;

use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Message\FrameAnalyze\AbstractAnalyzeFrameStepMessage;

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

    public function isIntermediateStep(): bool
    {
        return true;
    }
}
