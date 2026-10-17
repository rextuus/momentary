<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze\Result;

use App\Dto\VideoFrame;

readonly class FrameSplittingResult
{
    /** @var list<VideoFrame> */
    private array $frameList;

    /**
     * @param array<mixed> $rawFrameList Ausgabe von extract_frames.py: Liste aus {path, timestamp}
     *
     * @throws \UnexpectedValueException bei unerwartetem Format
     */
    public function __construct(
        array $rawFrameList,
        private string $frameDirPath,
    ) {
        $frames = [];
        foreach ($rawFrameList as $frame) {
            if (!\is_array($frame) || !\is_string($frame['path'] ?? null) || !is_numeric($frame['timestamp'] ?? null)) {
                throw new \UnexpectedValueException(
                    'Unexpected frame entry from extract_frames.py: ' . json_encode($frame),
                );
            }
            $frames[] = new VideoFrame($frame['path'], (int) $frame['timestamp']);
        }
        $this->frameList = $frames;
    }

    /**
     * @return list<VideoFrame>
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
