<?php

namespace App\Service\Video\Processing;

interface VideoProcessStepMessageHandlerInterface
{
    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void;

    public function decorateNextCurrentStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void;
}
