<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\ThumbnailExtraction;

use App\Service\Video\Processing\Attribute\StepOrder;

#[StepOrder(stepNumber: 4)]
class ExtractSceneThumbnailStepMessage extends AbstractExtractSceneThumbnailStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'EXTRACT SCENE THUMBNAIL';

    public function __construct(int $videoId, int $messageNrInVideoStack, string $comingFromStepMessageClass)
    {
        $this->videoId = $videoId;
        $this->messageNrInVideoStack = $messageNrInVideoStack;
        $this->comingFromStepMessageClass = $comingFromStepMessageClass;
    }

    public function getNextStepMessageClass(): string
    {
        return ExtractLastSceneThumbnailStepMessage::class;
    }
}
