<?php

namespace App\Message;

class AnalyzeSceneMessage
{
    public function __construct(private readonly int $sceneId)
    {
    }

    public function getSceneId(): int
    {
        return $this->sceneId;
    }
}
