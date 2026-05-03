<?php

declare(strict_types=1);

namespace App\Service\Video;

use App\Entity\VideoFace;

class VideoFaceMap
{
    /**
     * @param array<int, array<VideoFace>> $faces
     */
    public function __construct(
        private array $faces = [[]]
    )
    {
    }

    public function addFace(VideoFace $face): void
    {
        if (!array_key_exists($face->getTimestamp(), $this->faces)) {
            $this->faces[$face->getTimestamp()] = [];
        }

        $this->faces[$face->getTimestamp()][] = $face;
    }

    /**
     * @return array<int, array<VideoFace>>
     */
    public function getTimeStamps(): array
    {
        return $this->faces;
    }
}
