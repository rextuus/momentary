<?php

namespace App\Message;

class ConvertVideoMessage
{
    public function __construct(
        private int $videoId
    ) {}

    public function getVideoId(): int
    {
        return $this->videoId;
    }
}
