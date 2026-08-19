<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze\Result;

readonly class JellyfinExportResult
{
    public function __construct(
        private bool $success,
        private ?string $jellyfinPath = null,
        private ?string $jellyfinItemId = null,
        private ?string $errorMessage = null
    ) {}

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getJellyfinPath(): ?string
    {
        return $this->jellyfinPath;
    }

    public function getJellyfinItemId(): ?string
    {
        return $this->jellyfinItemId;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}