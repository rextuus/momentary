<?php

namespace App\Message;

final class ExtractSceneThumbnailMessage
{
    public function __construct(
        private int $sceneId,
        private array $remainingSceneIds = []
    ) {}

    public function getSceneId(): int
    {
        return $this->sceneId;
    }

    public function getRemainingSceneIds(): array
    {
        return $this->remainingSceneIds;
    }
}
