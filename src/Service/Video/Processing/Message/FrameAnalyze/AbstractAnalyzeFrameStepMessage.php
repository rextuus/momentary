<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\FrameAnalyze;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Exception\CommonProcessStepException;
use App\Service\Video\Processing\Message\Abstract\AbstractVideoProcessStepMessage;
use App\Service\Video\Processing\VideoMessageTrait;

abstract class AbstractAnalyzeFrameStepMessage extends AbstractVideoProcessStepMessage
{
    use VideoMessageTrait;

    private ?array $currentFrame = null;

    /**
     * @var array<string>
     */
    private array $remainingFrames = [];
    private ?int $timestamp = null;

    public function getVideoStatusForCurrentProcessStepEntity(): VideoStatus
    {
        return VideoStatus::ANALYZING_FACES;
    }

    /**
     * @throws CommonProcessStepException
     */
    public function getNextStepMessageClass(): string
    {
        throw new CommonProcessStepException('Implement getNextStepMessageClass');
    }

    public function getCurrentFrame(): ?array
    {
        return $this->currentFrame;
    }

    public function setCurrentFrame(?array $currentFrame): self
    {
        $this->currentFrame = $currentFrame;

        return $this;
    }

    /**
     * @return array<string>
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

    public function getTimestamp(): ?int
    {
        return $this->timestamp;
    }

    public function setTimestamp(?int $timestamp): self
    {
        $this->timestamp = $timestamp;

        return $this;
    }
}
