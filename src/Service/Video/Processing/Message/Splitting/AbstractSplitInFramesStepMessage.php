<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\Splitting;

use App\Dto\VideoFrame;
use App\Service\Video\Processing\Message\Abstract\AbstractVideoProcessStepMessage;

abstract class AbstractSplitInFramesStepMessage extends AbstractVideoProcessStepMessage
{
    /** @var list<int> */
    private array $remainingSceneIds = [];
    /** @var list<VideoFrame> */
    private array $framePathCollection = [];

    public function getCurrentSceneId(): ?int
    {
        return $this->currentSceneId;
    }

    public function setCurrentSceneId(?int $currentSceneId): self
    {
        $this->currentSceneId = $currentSceneId;

        return $this;
    }

    /**
     * @return list<int>
     */
    public function getRemainingSceneIds(): array
    {
        return $this->remainingSceneIds;
    }

    /**
     * @param list<int> $remainingSceneIds
     */
    public function setRemainingSceneIds(array $remainingSceneIds): self
    {
        $this->remainingSceneIds = $remainingSceneIds;

        return $this;
    }

    /**
     * @return list<VideoFrame>
     */
    public function getFramePathCollection(): array
    {
        return $this->framePathCollection;
    }

    /**
     * @param list<VideoFrame> $framePathCollection
     */
    public function setFramePathCollection(array $framePathCollection): self
    {
        $this->framePathCollection = $framePathCollection;

        return $this;
    }
}
