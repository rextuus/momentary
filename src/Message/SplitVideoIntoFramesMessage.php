<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SplitVideoIntoFramesMessage
{
    public function __construct(
        private int $videoId,
        private string $localVideoPath
    ) {}

    public function getVideoId(): int
    {
        return $this->videoId;
    }

    public function getLocalVideoPath(): string
    {
        return $this->localVideoPath;
    }
}