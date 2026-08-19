<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\Tagging;

use App\Entity\VideoScene;
use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Video\Analyze\TaggingService;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Message\Tagging\TagFirstSceneStepMessage;
use App\Service\Video\Processing\Message\Tagging\TagSceneStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[StepOrder(stepNumber: 18)]
class TagFirstSceneStepMessageHandler extends AbstractTagSceneStepMessageHandler
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

    public function __invoke(TagFirstSceneStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $this->startCurrentStep();

        $scenes = $video->getScenes()->toArray();
        $sceneIds = array_map(static fn (VideoScene $scene) => $scene->getId(), $scenes);

        if (count($sceneIds) === 0) {
            $this->stopProcessing(sprintf('No scenes found for video %d to tag.', $video->getId()));

            return;
        }

        // Special-Case: Only one scene exists
        if (count($sceneIds) === 1) {
            $firstSceneId = array_shift($sceneIds);
            $this->processSceneTagging($firstSceneId);

            $successMsg = sprintf('Video %d contains only one scene. Tagged successfully.', $video->getId());
            $this->finishCurrentStep($successMsg);

            return;
        }

        // Analyze first scene
        $this->currentSceneId = array_shift($sceneIds);
        $this->processSceneTagging($this->currentSceneId);

        $successMsg = sprintf(
            'First scene (ID %d) of Video "%s" tagged. %d left.',
            $this->currentSceneId,
            $video->getTitle(),
            count($sceneIds)
        );

        // Prepare next
        $this->currentSceneId = array_shift($sceneIds);
        $this->remainingSceneIds = $sceneIds;

        $this->finishCurrentStep($successMsg);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        /** @var TagSceneStepMessage $nextStepMessage */
        $nextStepMessage->setCurrentSceneId($this->currentSceneId);
        $nextStepMessage->setRemainingSceneIds($this->remainingSceneIds);
    }
}