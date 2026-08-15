<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\ThumbnailExtraction;

use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;

#[StepOrder(stepNumber: 3)]
class ExtractFirstSceneThumbnailStepMessage extends AbstractExtractSceneThumbnailStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'EXTRACT FIRST SCENE THUMBNAIL';

    public function __construct(int $videoId, int $messageNrInVideoStack, string $comingFromStepMessageClass)
    {
        $this->videoId = $videoId;
        $this->messageNrInVideoStack = $messageNrInVideoStack;
        $this->comingFromStepMessageClass = $comingFromStepMessageClass;
    }

    public function getNextStepMessageClass(): string
    {
        return ExtractSceneThumbnailStepMessage::class;
    }

    public function getTransitionToStatusNextStepIsBelonging(): VideoWorkflowProcessTransition
    {
        return VideoWorkflowProcessTransition::START_EXTRACTING_THUMBNAILS;
    }

    public function nextStepNeedsTransition(): bool
    {
        return true;
    }
}
