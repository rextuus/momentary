<?php

declare(strict_types=1);

namespace App\Dto;

class VideoFrame
{
    public function __construct(
        private string $path,
        private int $timestamp
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function setTimestamp(int $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
    }
}
