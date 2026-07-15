<?php

namespace App\Message;

final class ExtractSceneThumbnailMessage
{
    public function __construct(
        private int $sceneId
    ) {}

    public function getSceneId(): int
    {
        return $this->sceneId;
    }
}
