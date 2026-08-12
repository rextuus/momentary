<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message;

use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Message\Abstract\AbstractAnalyzeFrameStepMessage;

#[StepOrder(stepNumber: 8)]
class AnalyzeFrameStepMessage extends AbstractAnalyzeFrameStepMessage
{
    public function __construct(int $videoId)
    {
        $this->videoId = $videoId;
    }

    public function getNextStepMessageClass(): string
    {
        return AnalyzeLastFrameStepMessage::class;
    }
}
