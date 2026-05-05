<?php

declare(strict_types=1);

namespace App\Message;

final readonly class DownloadVideoMessage
{
    public function __construct(
        private int $videoId
    ) {}

    public function getVideoId(): int
    {
        return $this->videoId;
    }
}