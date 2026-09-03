<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\Tagging;

use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Video\Analyze\TaggingService;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Message\Tagging\TagLastSceneStepMessage;
use App\Service\Video\Processing\Message\Tagging\TagSceneStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 19)]
class TagSceneStepMessageHandler extends AbstractTagSceneStepMessageHandler
{
    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        TaggingService $taggingService,
        VideoSceneRepository $videoSceneRepository,
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService, $taggingService, $videoSceneRepository);
    }

    public function __invoke(TagSceneStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $this->startCurrentStep();

        // Special-Case: Already processed in first message
        if ($message->getCurrentSceneId() === null) {
            $successMsg = sprintf(
                'Video %d contained only one scene which was already tagged. Proceeding to final tag step.',
                $video->getId()
            );
            $this->finishCurrentStep($successMsg);

            return;
        }

        // Special-Case: Last scene reached
        if (count($message->getRemainingSceneIds()) === 0) {
            $successMsg = sprintf(
                'All scenes for video %d tagged. Forwarding to final step.',
                $video->getId()
            );

            $result = $this->processSceneTagging($message->getCurrentSceneId());
            if (!$result->isSuccess()) {
                $this->stopProcessing($result->getMessage());
                return;
            }

            $this->finishCurrentStep($successMsg);

            return;
        }

        // Tag current scene
        $this->currentSceneId = $message->getCurrentSceneId();
        $result = $this->processSceneTagging($this->currentSceneId);
        if (!$result->isSuccess()) {
            $this->stopProcessing($result->getMessage());
            return;
        }
        $oldSceneId = $this->currentSceneId;

        $remainingSceneIds = $message->getRemainingSceneIds();
        $this->currentSceneId = array_shift($remainingSceneIds);
        $this->remainingSceneIds = $remainingSceneIds;

        if (count($this->remainingSceneIds) === 0) {
            $successMsg = sprintf(
                'Scene ID %d of Video "%s" tagged. Only 1 left. Dispatching final message.',
                $oldSceneId,
                $video->getTitle()
            );

            $this->finishCurrentStep($successMsg);

            return;
        }

        $successMsg = sprintf(
            'Scene ID %d of Video "%s" tagged. %d left.',
            $oldSceneId,
            $video->getTitle(),
            count($this->remainingSceneIds)
        );

        $this->dispatchNextMessageOfCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var TagLastSceneStepMessage $nextStepMessage */
        $nextStepMessage->setCurrentSceneId($this->currentSceneId);
        $nextStepMessage->setRemainingSceneIds($this->remainingSceneIds);
    }

    public function decorateNextCurrentStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var TagSceneStepMessage $nextStepMessage */
        $nextStepMessage->setCurrentSceneId($this->currentSceneId);
        $nextStepMessage->setRemainingSceneIds($this->remainingSceneIds);
    }
}