<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\FrameAnalyze;

use App\Dto\VideoFrame;
use App\Enum\VideoStatus;
use App\Service\Video\Processing\Exception\CommonProcessStepException;
use App\Service\Video\Processing\Message\Abstract\AbstractVideoProcessStepMessage;
use App\Service\Video\Processing\VideoMessageTrait;

abstract class AbstractAnalyzeFrameStepMessage extends AbstractVideoProcessStepMessage
{
    use VideoMessageTrait;

    private ?VideoFrame $currentFrame = null;

    /**
     * @var array<VideoFrame>
     */
    private array $remainingFrames = [];

    public function getVideoStatusForCurrentProcessStepEntity(): VideoStatus
    {
        return VideoStatus::ANALYZING_FACES_INITIAL;
    }

    /**
     * @throws CommonProcessStepException
     */
    public function getNextStepMessageClass(): ?string
    {
        throw new CommonProcessStepException('Implement getNextStepMessageClass');
    }

    public function getCurrentFrame(): ?VideoFrame
    {
        return $this->currentFrame;
    }

    public function setCurrentFrame(?VideoFrame $currentFrame): self
    {
        $this->currentFrame = $currentFrame;

        return $this;
    }

    /**
     * @return array<VideoFrame>
     */
    public function getRemainingFrames(): array
    {
        return $this->remainingFrames;
    }

    public function setRemainingFrames(array $remainingFrames): self
    {
        $this->remainingFrames = $remainingFrames;

        return $this;
    }
}
