<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze\Result;

readonly class EmptyScenesMergerResult
{
    public function __construct(
        private bool $processed,
        private int $originalSceneCount,
        private int $mergedSceneCount,
        private int $removedSceneCount,
        private bool $fallbackAllMerged,
        private ?string $skippedReason = null
    ) {
    }

    public static function skipped(string $reason, int $sceneCount = 0): self
    {
        return new self(
            processed: false,
            originalSceneCount: $sceneCount,
            mergedSceneCount: $sceneCount,
            removedSceneCount: 0,
            fallbackAllMerged: false,
            skippedReason: $reason
        );
    }

    public static function success(
        int $originalSceneCount,
        int $mergedSceneCount,
        bool $fallbackAllMerged = false
    ): self {
        return new self(
            processed: true,
            originalSceneCount: $originalSceneCount,
            mergedSceneCount: $mergedSceneCount,
            removedSceneCount: $originalSceneCount - $mergedSceneCount,
            fallbackAllMerged: $fallbackAllMerged
        );
    }

    public function isProcessed(): bool
    {
        return $this->processed;
    }

    public function getOriginalSceneCount(): int
    {
        return $this->originalSceneCount;
    }

    public function getMergedSceneCount(): int
    {
        return $this->mergedSceneCount;
    }

    public function getRemovedSceneCount(): int
    {
        return $this->removedSceneCount;
    }

    public function isFallbackAllMerged(): bool
    {
        return $this->fallbackAllMerged;
    }

    public function getSkippedReason(): ?string
    {
        return $this->skippedReason;
    }
}