<?php

namespace App\Message;

class AnalyzeSceneMessage
{
    public function __construct(
        private readonly int $sceneId,
        private readonly array $remainingSceneIds = []
    ) {
    }

    public function getSceneId(): int
    {
        return $this->sceneId;
    }

    public function getRemainingSceneIds(): array
    {
        return $this->remainingSceneIds;
    }
}
