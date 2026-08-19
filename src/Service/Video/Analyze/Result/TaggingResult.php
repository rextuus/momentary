<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze\Result;

readonly class TaggingResult
{
    /**
     * @param int[] $sceneIds
     */
    public function __construct(
        private bool $success,
        private bool $hasScenes,
        private array $sceneIds = [],
        private ?string $message = null
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function hasScenes(): bool
    {
        return $this->hasScenes;
    }

    /**
     * @return int[]
     */
    public function getSceneIds(): array
    {
        return $this->sceneIds;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }
}