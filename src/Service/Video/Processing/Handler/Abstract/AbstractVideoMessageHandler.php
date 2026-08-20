<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\Abstract;

use App\Entity\Video;
use App\Repository\VideoRepository;
use App\Service\Video\Processing\Exception\StepMessageDecorationException;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageHandlerInterface;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
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

    protected function initHandler(VideoProcessStepMessageInterface $message): Video
    {
        $this->setCurrentMessage($message);
        $this->startCurrentStep();

        return $this->getVideo();
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
        $currentMessage = $this->getCurrentMessage();

        // Re-fetch or refresh the video entity so we work with the up-to-date state from DB
        $video = $this->videoRepository->find($currentMessage->getVideoId());
        $this->video = $video;

        // processing chain finished
        if (null === $nextStepMessage) {
            $finalMessage = sprintf(
                'Processing chain finished for video "%s"',
                $this->video->getTitle()
            );
            echo '[✓✓] ' . $finalMessage . PHP_EOL;

            $transition = $currentMessage->getTransitionToStatusNextStepIsBelonging()->value;
            if ($this->workflowMachine->can($video, $transition)) {
                $this->workflowMachine->apply($video, $transition);
            }

            return;
        }

        // make sure we switch the state before dispatching the next message if necessary
        if ($currentMessage->nextStepNeedsTransition()) {

            $transition = $currentMessage->getTransitionToStatusNextStepIsBelonging()->value;

//            dump('try to apply transition: ' . $transition . ' in handler: ' . get_class($this));
//            dump('video status before transition: ' . $video->getStatus()->value);
//            dump($this->workflowMachine->can($video, $transition));
            if ($this->workflowMachine->can($video, $transition)) {
                $this->workflowMachine->apply($video, $transition);
//                dump('Transition applied: ' . $transition . ' in handler: ' . get_class($this));
            }
//            dump("");
        }

        // dispatch the next message
        $this->dispatcher->dispatch($nextStepMessage);
    }

    protected function getNextStepMessageInstance(VideoProcessStepMessageInterface $message): ?VideoProcessStepMessageInterface
    {
        $nextStepMessageClass = $message->getNextStepMessageClass();
        if (null === $nextStepMessageClass) {
            return null;
        }

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

        // Fetch a managed instance of the video
        $video = $this->videoRepository->find($this->getCurrentMessage()->getVideoId());

        if ($this->workflowMachine->can($video, VideoWorkflowProcessTransition::FAIL->value)) {
            $this->workflowMachine->apply($video, VideoWorkflowProcessTransition::FAIL->value);
            $this->videoRepository->getEntityManagerPublic()->flush();
        }

        $this->processingService->failStep(
            $video,
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

        $initialTransition = $this->getCurrentMessage()->getInitialTransition();
        if ($initialTransition !== null) {
            $video = $this->getVideo();
            if ($this->workflowMachine->can($video, $initialTransition->value)) {
                $this->workflowMachine->apply($video, $initialTransition->value);
            }
        }
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
