<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\ThumbnailExtraction;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Exception\CommonProcessStepException;
use App\Service\Video\Processing\Message\Abstract\AbstractVideoProcessStepMessage;
use App\Service\Video\Processing\VideoMessageTrait;

abstract class AbstractExtractSceneThumbnailStepMessage extends AbstractVideoProcessStepMessage
{
    use VideoMessageTrait;

    private ?int $currentSceneId = null;
    private array $remainingSceneIds = [];
    private int $processedScenes = 0;
    private int $totalScenes = 0;

    public function getVideoStatusForCurrentProcessStepEntity(): VideoStatus
    {
        return VideoStatus::EXTRACTING_THUMBNAILS;
    }

    /**
     * @throws CommonProcessStepException
     */
    public function getNextStepMessageClass(): ?string
    {
        throw new CommonProcessStepException('Implement getNextStepMessageClass');
    }

    public function getCurrentSceneId(): ?int
    {
        return $this->currentSceneId;
    }

    public function setCurrentSceneId(?int $currentSceneId): self
    {
        $this->currentSceneId = $currentSceneId;

        return $this;
    }

    public function getRemainingSceneIds(): array
    {
        return $this->remainingSceneIds;
    }

    public function setRemainingSceneIds(array $remainingSceneIds): self
    {
        $this->remainingSceneIds = $remainingSceneIds;

        return $this;
    }

    public function getProcessedScenes(): int
    {
        return $this->processedScenes;
    }

    public function setProcessedScenes(int $processedScenes): self
    {
        $this->processedScenes = $processedScenes;
        return $this;
    }

    public function getTotalScenes(): int
    {
        return $this->totalScenes;
    }

    public function setTotalScenes(int $totalScenes): self
    {
        $this->totalScenes = $totalScenes;
        return $this;
    }
}
