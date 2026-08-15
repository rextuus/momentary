<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\Abstract;

use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Exception\StepMessageTransitionException;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;

abstract class AbstractVideoProcessStepMessage implements VideoProcessStepMessageInterface
{
    protected const string MESSAGE_LOGGING_IDENT = 'NOT DECLARED';

    protected int $videoId;
    protected int $messageNrInVideoStack;

    /**
     * @var class-string
     */
    protected string $comingFromStepMessageClass;

    public function getMessageLoggingIdent(): string
    {
        return static::MESSAGE_LOGGING_IDENT;
    }

    public function isIntermediateStep(): bool
    {
        return false;
    }

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

    public function getCurrentSceneId(): ?int
    {
        return $this->currentSceneId;
    }

    public function setCurrentSceneId(?int $currentSceneId): self
    {
        $this->currentSceneId = $currentSceneId;

        return $this;
    }

    public function getMessageNrInVideoStack(): int
    {
        return $this->messageNrInVideoStack;
    }

    public function setMessageNrInVideoStack(int $messageNrInVideoStack): self
    {
        $this->messageNrInVideoStack = $messageNrInVideoStack;
        return $this;
    }

    public function getComingFromStepMessageClass(): string
    {
        return $this->comingFromStepMessageClass;
    }

    public function setComingFromStepMessageClass(string $comingFromStepMessageClass): self
    {
        $this->comingFromStepMessageClass = $comingFromStepMessageClass;
        return $this;
    }
}
