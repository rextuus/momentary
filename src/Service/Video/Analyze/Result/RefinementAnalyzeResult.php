<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze\Result;

readonly class RefinementAnalyzeResult
{
    private function __construct(
        private array $sceneIds,
    ) {
    }

    /**
     * @param array<int> $sceneIds
     */
    public static function create(array $sceneIds): RefinementAnalyzeResult
    {
        return new self($sceneIds);
    }

    /**
     * @return array<int>
     */
    public function getSceneIds(): array
    {
        return $this->sceneIds;
    }

    public function getSceneCount(): int
    {
        return count($this->sceneIds);
    }
}
