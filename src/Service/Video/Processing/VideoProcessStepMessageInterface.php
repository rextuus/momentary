<?php

namespace App\Service\Video\Processing;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Exception\StepMessageTransitionException;

interface VideoProcessStepMessageInterface
{
    public function getVideoId(): int;

    /**
     * @throws StepMessageTransitionException
     */
    public function getTransitionToStatusNextStepIsBelonging(): VideoWorkflowProcessTransition;
    public function nextStepNeedsTransition(): bool;

    public function getInitialTransition(): ?VideoWorkflowProcessTransition;

    public function getVideoStatusForCurrentProcessStepEntity(): VideoStatus;

    /**
     * @return class-string<VideoProcessStepMessageInterface>
     */
    public function getNextStepMessageClass(): string;

    /**
     * @return class-string<VideoProcessStepMessageInterface>
     */
    public function getCurrentStepMessageClass(): string;

    public function getMessageLoggingIdent(): string;

    public function isIntermediateStep(): bool;

    public function getMessageNrInVideoStack(): int;

    public function getComingFromStepMessageClass(): string;
}