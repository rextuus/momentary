<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\Splitting;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Message\Abstract\AbstractVideoProcessStepMessage;

abstract class AbstractSplitInFramesStepMessage extends AbstractVideoProcessStepMessage
{
    protected ?int $sceneId = null;

    public function getVideoStatusForCurrentProcessStepEntity(): VideoStatus
    {
        // TODO: Implement getVideoStatusForCurrentProcessStepEntity() method.
    }

    public function getNextStepMessageClass(): string
    {
        // TODO: Implement getNextStepMessageClass() method.
    }

    public function getSceneId(): ?int
    {
        return $this->sceneId;
    }

    public function setSceneId(?int $sceneId): self
    {
        $this->sceneId = $sceneId;
        return $this;
    }
}
