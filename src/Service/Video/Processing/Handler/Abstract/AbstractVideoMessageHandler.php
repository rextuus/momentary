<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\Abstract;

use App\Entity\Video;
use App\Repository\VideoRepository;
use App\Service\Video\Processing\Exception\StepMessageDecorationException;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageHandlerInterface;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use LogicException;

abstract class AbstractVideoMessageHandler implements VideoProcessStepMessageHandlerInterface
{
    protected ?Video $video = null;
    protected ?VideoProcessStepMessageInterface $currentMessage = null;

    public function __construct(
        protected VideoRepository $videoRepository,
        protected VideoProcessMessageDispatcher $dispatcher,
        protected WorkflowMachine $workflowMachine,
        protected VideoProcessingService $processingService
    ) {
    }

    protected function setCurrentMessage(VideoProcessStepMessageInterface $message): void
    {
        $this->currentMessage = $message;
    }

    protected function getVideo(): Video
    {
        if ($this->video === null) {
            return $this->video = $this->videoRepository->find($this->getCurrentMessage()->getVideoId());
        }

        return $this->video;
    }

    protected function getCurrentMessage(): VideoProcessStepMessageInterface
    {
        if ($this->currentMessage === null) {
            throw new LogicException('Current message is not set');
        }

        return $this->currentMessage;
    }

    protected function dispatchNextMessageOfCurrentStep(string $logMessage): void
    {
        echo '[i] ' . $logMessage . PHP_EOL;

        $nextCurrentStepMessage = $this->getCurrentStepMessageInstance($this->getCurrentMessage());
        $this->dispatcher->dispatch($nextCurrentStepMessage);
    }

    private function dispatchFirstMessageOfNextStep(): void
    {
        // get the new message type
        $nextStepMessage = $this->getNextStepMessageInstance($this->getCurrentMessage());

        // make sure we switch the state before dispatching the next message if necessary
        $currentMessage = $this->getCurrentMessage();
        if ($currentMessage->nextStepNeedsTransition()) {
            // Re-fetch or refresh the video entity so we work with the up-to-date state from DB
            $video = $this->videoRepository->find($currentMessage->getVideoId());
            $this->video = $video;

            $transition = $currentMessage->getTransitionToStatusNextStepIsBelonging()->value;

            if ($this->workflowMachine->can($video, $transition)) {
                $this->workflowMachine->apply($video, $transition);
                dump('Transition applied: ' . $transition);
            }
        }

        // dispatch the next message
        $this->dispatcher->dispatch($nextStepMessage);
    }

    protected function getNextStepMessageInstance(VideoProcessStepMessageInterface $message): VideoProcessStepMessageInterface
    {
        $nextStepMessageClass = $message->getNextStepMessageClass();
        $nextStepMessage = new $nextStepMessageClass(
            $message->getVideoId(),
            $message->getMessageNrInVideoStack() + 1,
            get_class($message)
        );

        $this->decorateNextStepMessage($nextStepMessage);

        return $nextStepMessage;
    }

    protected function getCurrentStepMessageInstance(VideoProcessStepMessageInterface $message): VideoProcessStepMessageInterface
    {
        $nextCurrentStepMessageClass = $message->getCurrentStepMessageClass();
        $nextCurrentStepMessage = new $nextCurrentStepMessageClass(
            $message->getVideoId(),
            $message->getMessageNrInVideoStack() + 1,
            get_class($message)
        );

        $this->decorateNextCurrentStepMessage($nextCurrentStepMessage);

        return $nextCurrentStepMessage;
    }

    protected function stopProcessing(string $errorMsg): void
    {
        echo $errorMsg . PHP_EOL;

        $this->processingService->failStep(
            $this->getVideo(),
            $this->getCurrentMessage()->getVideoStatusForCurrentProcessStepEntity(),
            $errorMsg
        );
    }

    protected function startCurrentStep(): void
    {
        $this->processingService->startStep(
            $this->getVideo(),
            $this->getCurrentMessage()->getVideoStatusForCurrentProcessStepEntity(),
            $this->getCurrentMessage()
        );
    }

    protected function finishCurrentStep(string $successMessage): void
    {
        echo '[i] ' . $successMessage . PHP_EOL;

        $this->processingService->finishStep(
            $this->getVideo(),
            $this->getCurrentMessage()->getVideoStatusForCurrentProcessStepEntity(),
            $this->getCurrentMessage()
        );

        $this->dispatchFirstMessageOfNextStep();
    }

    /**
     * @throws StepMessageDecorationException
     */
    public function decorateNextCurrentStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        throw new StepMessageDecorationException('Should not be called');
    }
}
