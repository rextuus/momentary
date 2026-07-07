<?php

namespace App\Message;

class ExtractThumbnailMessage
{
    public function __construct(
        private int $videoId,
        private float $timeInSeconds = 0.0
    ) {}

    public function getVideoId(): int
    {
        return $this->videoId;
    }

    public function getTimeInSeconds(): float
    {
        return $this->timeInSeconds;
    }
}
