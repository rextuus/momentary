<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\Splitting;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Message\Abstract\AbstractVideoProcessStepMessage;

abstract class AbstractSplitInFramesStepMessage extends AbstractVideoProcessStepMessage
{
    protected ?int $currentSceneId = null;

    private array $remainingSceneIds = [];
    private array $framePathCollection = [];

    public function getVideoStatusForCurrentProcessStepEntity(): VideoStatus
    {
        // TODO: Implement getVideoStatusForCurrentProcessStepEntity() method.
    }

    public function getNextStepMessageClass(): string
    {
        // TODO: Implement getNextStepMessageClass() method.
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


    public function getRemainingSceneIds(): array
    {
        return $this->remainingSceneIds;
    }

    public function setRemainingSceneIds(array $remainingSceneIds): self
    {
        $this->remainingSceneIds = $remainingSceneIds;

        return $this;
    }

    public function getFramePathCollection(): array
    {
        return $this->framePathCollection;
    }

    public function setFramePathCollection(array $framePathCollection): self
    {
        $this->framePathCollection = $framePathCollection;

        return $this;
    }
}
