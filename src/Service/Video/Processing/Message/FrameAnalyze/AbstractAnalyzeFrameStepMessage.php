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

    private ?string $framePath = null;
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

    public function getFramePath(): ?string
    {
        return $this->framePath;
    }

    public function setFramePath(?string $framePath): self
    {
        $this->framePath = $framePath;

        return $this;
    }

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
