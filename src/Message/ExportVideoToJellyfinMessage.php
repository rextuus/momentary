<?php

namespace App\Message;

class ExportVideoToJellyfinMessage
{
    public function __construct(
        private int $videoId
    ) {
    }

    public function getVideoId(): int
    {
        return $this->videoId;
    }
}
