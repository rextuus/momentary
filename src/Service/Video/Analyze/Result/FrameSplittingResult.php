<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze\Result;

use App\Dto\VideoFrame;

readonly class FrameSplittingResult
{
    /** @var array<VideoFrame> */
    private array $frameList;

    public function __construct(
        array $rawFrameList,
        private string $frameDirPath
    ) {
        $this->frameList = array_map(
            fn(array $frame) => new VideoFrame($frame['path'], (int)$frame['timestamp']),
            $rawFrameList
        );
    }

    /**
     * @return array<VideoFrame>
     */
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
