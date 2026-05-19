<?php

namespace App\Message;

final readonly class FrameAnalyzerMessage
{
    public function __construct(
        private int $videoId,
        private string $framePath,
        private int $timestamp,
        private bool $isLast = false,
        private bool $isRefinement = false
    ) {}

    public function getVideoId(): int
    {
        return $this->videoId;
    }

    public function getFramePath(): string
    {
        return $this->framePath;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function isLast(): bool
    {
        return $this->isLast;
    }

    public function isRefinement(): bool
    {
        return $this->isRefinement;
    }
}
