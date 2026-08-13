<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\ThumbnailExtraction;

use App\Service\Video\Processing\Attribute\StepOrder;

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
