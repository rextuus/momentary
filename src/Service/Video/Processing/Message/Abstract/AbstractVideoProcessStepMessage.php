<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\Abstract;

use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Exception\StepMessageTransitionException;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;

abstract class AbstractVideoProcessStepMessage implements VideoProcessStepMessageInterface
{
    protected int $videoId;

    public function getCurrentStepMessageClass(): string
    {
        return static::class;
    }

    public function nextStepNeedsTransition(): bool
    {
        return false;
    }

    public function getTransitionToStatusNextStepIsExpecting(): VideoWorkflowProcessTransition
    {
        throw new StepMessageTransitionException('This message should not run a transition.');
    }

    public function getVideoId(): int
    {
        return $this->videoId;
    }
}
