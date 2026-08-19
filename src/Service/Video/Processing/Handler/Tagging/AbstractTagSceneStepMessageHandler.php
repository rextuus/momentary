<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\Tagging;

use App\Entity\VideoScene;
use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Video\Analyze\Result\TaggingResult;
use App\Service\Video\Analyze\TaggingService;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;

abstract class AbstractTagSceneStepMessageHandler extends AbstractVideoMessageHandler
{
    protected ?int $currentSceneId = null;
    /** @var array<int> */
    protected array $remainingSceneIds = [];

    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        protected readonly TaggingService $taggingService,
        protected readonly VideoSceneRepository $videoSceneRepository,
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    protected function processSceneTagging(?int $sceneId): TaggingResult
    {
        if ($sceneId === null) {
            return new TaggingResult(
                false,
                true,
                [],
                'No Scene given'
            );
        }

        $scene = $this->videoSceneRepository->find($sceneId);

        if (!$scene instanceof VideoScene) {
            $this->stopProcessing(sprintf('Scene with ID %d not found for tagging.', $sceneId));

            return new TaggingResult(
                false,
                true,
                [],
                sprintf('Scene with ID %d not found for tagging.', $sceneId)
            );
        }

        return $this->taggingService->tagScene($scene);
    }
}
