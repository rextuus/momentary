<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze\Result;

readonly class ChapterGenerationResult
{
    public function __construct(
        private bool $success,
        private int $chapterCount = 0,
        private ?string $errorMessage = null
    ) {}

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getChapterCount(): int
    {
        return $this->chapterCount;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}