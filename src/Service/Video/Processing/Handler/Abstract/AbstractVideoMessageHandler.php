<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\Abstract;

use App\Entity\Video;
use App\Repository\VideoRepository;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\Video\Processing\Exception\StepMessageDecorationException;
use App\Service\Video\Processing\Exception\VideoGoneException;
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
        protected VideoProcessingService $processingService,
    ) {}

    protected function initHandler(VideoProcessStepMessageInterface $message): Video
    {
        $this->setCurrentMessage($message);
        $this->startCurrentStep();

        return $this->getVideo();
    }

    protected function setCurrentMessage(VideoProcessStepMessageInterface $message): void
    {
        $this->currentMessage = $message;
        // WICHTIG: Handler-Services leben im Worker über viele Messages hinweg.
        // Ohne Reset würde das Video der vorherigen Message (ggf. gelöscht) weiterverwendet.
        $this->video = null;
        $this->resetState();
    }

    /**
     * Setzt den Zustand der vorherigen Message zurück. Handler mit eigenen Feldern (Szenen-/Frame-Listen) müssen
     * diese hier leeren, sonst landen bei frühem Return Daten eines anderen Videos in der nächsten Message.
     */
    protected function resetState(): void {}

    protected function getVideo(): Video
    {
        if ($this->video === null) {
            $videoId = $this->getCurrentMessage()->getVideoId();
            $video = $this->videoRepository->find($videoId);
            if (!$video) {
                throw VideoGoneException::forId($videoId);
            }
            $this->video = $video;
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

        // Re-fetch the video entity so we work with the up-to-date state from DB
        $video = $this->videoRepository->find($currentMessage->getVideoId());
        if (!$video) {
            throw VideoGoneException::forId($currentMessage->getVideoId());
        }
        $this->video = $video;

        // processing chain finished
        if (null === $nextStepMessage) {
            $finalMessage = sprintf('Processing chain finished for video "%s"', $video->getTitle());
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

            if ($this->workflowMachine->can($video, $transition)) {
                $this->workflowMachine->apply($video, $transition);
            }
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
            get_class($message),
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
            get_class($message),
        );

        $this->decorateNextCurrentStepMessage($nextCurrentStepMessage);

        return $nextCurrentStepMessage;
    }

    protected function stopProcessing(string $errorMsg): void
    {
        echo $errorMsg . PHP_EOL;

        // Fetch a managed instance of the video
        $videoId = $this->getCurrentMessage()->getVideoId();
        $video = $this->videoRepository->find($videoId);
        if (!$video) {
            // Video wurde währenddessen gelöscht -> nichts mehr zu tun
            throw VideoGoneException::forId($videoId);
        }

        if ($this->workflowMachine->can($video, VideoWorkflowProcessTransition::FAIL->value)) {
            $this->workflowMachine->apply($video, VideoWorkflowProcessTransition::FAIL->value);
            $this->videoRepository->getEntityManagerPublic()->flush();
        }

        $this->processingService->failStep(
            $video,
            $this->getCurrentMessage()->getVideoStatusForCurrentProcessStepEntity(),
            $errorMsg,
        );
    }

    /**
     * Fortschritt melden: ein Element (Frame, Szene) des laufenden Schritts erledigt, $remaining stehen noch aus.
     */
    protected function recordItemDone(int $remaining): void
    {
        $this->getVideo()->recordProgressItem(
            $this->getCurrentMessage()->getVideoStatusForCurrentProcessStepEntity(),
            $remaining,
        );
        $this->videoRepository->getEntityManagerPublic()->flush();
    }

    protected function startCurrentStep(): void
    {
        $this->processingService->startStep(
            $this->getVideo(),
            $this->getCurrentMessage()->getVideoStatusForCurrentProcessStepEntity(),
            $this->getCurrentMessage(),
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
            $this->getCurrentMessage(),
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
