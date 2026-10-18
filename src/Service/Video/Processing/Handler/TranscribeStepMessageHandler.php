<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler;

use App\Enum\TranscriptionStatus;
use App\Repository\VideoRepository;
use App\Service\Transcription\TranscriptionSettingsProvider;
use App\Service\Transcription\VideoTranscriber;
use App\Service\Video\Processing\Attribute\StepOrder;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\TranscribeStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Letzter Schritt: Ton transkribieren. Scheitert die Transkription (z. B. keine Tonspur), ist das Video trotzdem
 * fertig; der Fehler steht im Transkript und lässt sich dort neu anstoßen.
 */
#[AsMessageHandler]
#[StepOrder(stepNumber: 25)]
class TranscribeStepMessageHandler extends AbstractVideoMessageHandler
{
    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        private readonly VideoTranscriber $transcriber,
        private readonly TranscriptionSettingsProvider $settingsProvider,
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    public function __invoke(TranscribeStepMessage $message): void
    {
        $this->setCurrentMessage($message);
        $video = $this->getVideo();
        $this->startCurrentStep();

        if (!$this->settingsProvider->get()->isTranscribeInPipeline()) {
            $this->finishCurrentStep('Transkription in der Pipeline ist abgeschaltet, Schritt übersprungen.');

            return;
        }

        $transcript = $this->transcriber->transcribe($video);

        $this->finishCurrentStep(match ($transcript->getStatus()) {
            TranscriptionStatus::DONE => sprintf(
                'Ton von "%s" transkribiert: %d Abschnitte.',
                $video->getTitle(),
                $transcript->getSegments()->count(),
            ),
            default => sprintf('Transkription fehlgeschlagen: %s', (string) $transcript->getError()),
        });
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void {}
}
