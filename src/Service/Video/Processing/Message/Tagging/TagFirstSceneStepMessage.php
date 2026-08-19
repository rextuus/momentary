<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Message\Tagging;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Message\Abstract\AbstractVideoProcessStepMessage;

#[StepOrder(stepNumber: 18)]
class TagFirstSceneStepMessage extends AbstractVideoProcessStepMessage
{
    protected const string MESSAGE_LOGGING_IDENT = 'TAG FIRST SCENE';

    private ?int $currentSceneId = null;
    /** @var int[] */
    private array $remainingSceneIds = [];

    public function __construct(int $videoId, int $messageNrInVideoStack, string $comingFromStepMessageClass)
    {
        $this->videoId = $videoId;
        $this->messageNrInVideoStack = $messageNrInVideoStack;
        $this->comingFromStepMessageClass = $comingFromStepMessageClass;
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

    /**
     * @return int[]
     */
    public function getRemainingSceneIds(): array
    {
        return $this->remainingSceneIds;
    }

    /**
     * @param int[] $remainingSceneIds
     */
    public function setRemainingSceneIds(array $remainingSceneIds): void
    {
        $this->remainingSceneIds = $remainingSceneIds;
    }

    public function getNextStepMessageClass(): ?string
    {
        return TagSceneStepMessage::class;
    }

    public function getCurrentStepMessageClass(): string
    {
        return TagSceneStepMessage::class;
    }

    public function getVideoStatusForCurrentProcessStepEntity(): VideoStatus
    {
        return VideoStatus::TAGGING_SCENES;
    }
}