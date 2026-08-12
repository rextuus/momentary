<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

readonly class FrameSplittingResult
{
    public function __construct(
        private array $frameList,
        private string $frameDirPath
    ) {
    }

    public function getFrameList(): array
    {
        return $this->frameList;
    }

    public function getFrameCount(): int
    {
        return count($this->frameList);
    }

    public function getFrameDirPath(): string
    {
        return $this->frameDirPath;
    }
}
