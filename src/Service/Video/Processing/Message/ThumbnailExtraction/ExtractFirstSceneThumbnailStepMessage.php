<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\ThumbnailExtraction;

use App\Service\Video\Processing\Attribute\StepOrder;

#[StepOrder(stepNumber: 3)]
class ExtractFirstSceneThumbnailStepMessage extends AbstractExtractSceneThumbnailStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'EXTRACT FIRST SCENE THUMBNAIL';

    public function __construct(int $videoId)
    {
        $this->videoId = $videoId;
    }

    public function getNextStepMessageClass(): string
    {
        return ExtractSceneThumbnailStepMessage::class;
    }
}
