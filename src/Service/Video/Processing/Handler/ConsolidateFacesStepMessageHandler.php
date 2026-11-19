<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler;

use App\Repository\VideoRepository;
use App\Service\Face\VerifiedFaceCarryover;
use App\Service\Face\VideoFaceConsolidator;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\ConsolidateFacesStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Gruppiert nach der (Refinement-)Analyse alle Gesichter des Videos zu Personen. Vorher übernehmen die neuen
 * Gesichter beim Neuverarbeiten die Bestätigungen der alten (VerifiedFaceCarryover); sie sind dann feste Anker.
 */
#[AsMessageHandler]
#[StepOrder(stepNumber: 17)]
class ConsolidateFacesStepMessageHandler extends AbstractVideoMessageHandler
{
    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        private readonly VideoFaceConsolidator $consolidator,
        private readonly VerifiedFaceCarryover $carryover,
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    public function __invoke(ConsolidateFacesStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $this->startCurrentStep();

        $carried = $this->carryover->apply($video);
        $result = $this->consolidator->consolidate($video);

        $this->finishCurrentStep(sprintf(
            '%d confirmed faces carried over. Consolidated %d faces of video "%s": %d persons before, %d after (%d faces reassigned, %d empty persons removed).',
            $carried,
            $result->faces,
            $video->getTitle(),
            $result->personsBefore,
            $result->personsAfter,
            $result->reassignedFaces,
            $result->removedPersons,
        ));
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void {}
}
