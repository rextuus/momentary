<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler;

use App\Repository\VideoRepository;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\EncodeWebStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\Video\WebVersion\WebVersionEncoder;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Web-Fassung erzeugen. Scheitert das, ist das Video trotzdem fertig: es wird dann das Original gestreamt, und
 * `app:video:web-versions` holt es später nach.
 */
#[AsMessageHandler]
#[StepOrder(stepNumber: 25)]
class EncodeWebStepMessageHandler extends AbstractVideoMessageHandler
{
    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        private readonly WebVersionEncoder $encoder,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    public function __invoke(EncodeWebStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $this->startCurrentStep();

        try {
            $result = $this->encoder->encode($video);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Web-Fassung für Video %d: %s', (int) $video->getId(), $e->getMessage()));
            $result = 'Web-Fassung fehlgeschlagen, es wird das Original gestreamt: ' . $e->getMessage();
        }

        $this->finishCurrentStep($result);
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void {}
}
