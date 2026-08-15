<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\ThumbnailExtraction;

use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Message\Splitting\Video\SplitVideoInFramesStepMessage;

#[StepOrder(stepNumber: 5)]
class ExtractLastSceneThumbnailStepMessage extends AbstractExtractSceneThumbnailStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'EXTRACT LAST SCENE THUMBNAIL';

    public function __construct(int $videoId, int $messageNrInVideoStack, string $comingFromStepMessageClass)
    {
        $this->videoId = $videoId;
        $this->messageNrInVideoStack = $messageNrInVideoStack;
        $this->comingFromStepMessageClass = $comingFromStepMessageClass;
    }

    public function getTransitionToStatusNextStepIsBelonging(): VideoWorkflowProcessTransition
    {
        return VideoWorkflowProcessTransition::START_SPLITTING;
    }

    public function getNextStepMessageClass(): string
    {
        return SplitVideoInFramesStepMessage::class;
    }

    public function nextStepNeedsTransition(): bool
    {
        return true;
    }
}
