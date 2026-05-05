<?php

declare(strict_types=1);

namespace App\Message;

final class DetectVideoScenesMessage
{
    public function __construct(
        private int $videoId,
        private string $videoPath
    ) {}

    public function getVideoId(): int
    {
        return $this->videoId;
    }

    public function getVideoPath(): string
    {
        return $this->videoPath;
    }
}