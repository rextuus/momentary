<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message;

use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Message\Abstract\AbstractExtractSceneThumbnailStepMessage;

#[StepOrder(stepNumber: 4)]
class ExtractSceneThumbnailStepMessage extends AbstractExtractSceneThumbnailStepMessage
{
    public function __construct(int $videoId)
    {
        $this->videoId = $videoId;
    }

    public function getNextStepMessageClass(): string
    {
        return ExtractLastSceneThumbnailStepMessage::class;
    }
}
