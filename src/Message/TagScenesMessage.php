<?php

namespace App\Message;

class TagScenesMessage
{
    public function __construct(private readonly int $videoId)
    {
    }

    public function getVideoId(): int
    {
        return $this->videoId;
    }
}
