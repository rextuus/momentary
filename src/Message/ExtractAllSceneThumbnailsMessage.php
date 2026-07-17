<?php

namespace App\Message;

final class ExtractAllSceneThumbnailsMessage
{
    public function __construct(
        private int $videoId
    ) {}

    public function getVideoId(): int
    {
        return $this->videoId;
    }
}
