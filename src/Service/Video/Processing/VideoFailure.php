<?php

declare(strict_types=1);

namespace App\Service\Video\Processing;

use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Service\Video\Processing\Enum\VideoWorkflowProcessTransition;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Lässt die Verarbeitung eines Videos sauber scheitern: Status "error", Grund am Video (Übersicht und Videoseite) und
 * am fehlgeschlagenen Schritt (Pipeline-Übersicht).
 *
 * Ohne das bliebe ein Video still in einem Verarbeitungsstatus stehen und blockierte einen Platz in der
 * Import-Warteschlange. Genutzt von FailedStepSubscriber (Worker gibt nach allen Retries auf), von den Handlern
 * (stopProcessing) und vom VideoProcessMessageDispatcher (nächster Schritt lässt sich nicht verschicken).
 */
class VideoFailure
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly WorkflowMachine $workflowMachine,
        private readonly VideoProcessingService $processingService,
        private readonly ManagerRegistry $managerRegistry,
    ) {}

    /**
     * @return bool false, wenn das Video nicht (mehr) existiert
     */
    public function fail(int $videoId, VideoStatus $step, string $reason): bool
    {
        // Der gescheiterte Schritt kann den EntityManager geschlossen oder mit einer kaputten Änderung hinterlassen haben;
        // sonst ließe sich der Fehler selbst nicht speichern. Der Schritt ist ohnehin vorbei.
        $entityManager = $this->managerRegistry->getManagerForClass(Video::class);
        if ($entityManager instanceof EntityManagerInterface && !$entityManager->isOpen()) {
            $this->managerRegistry->resetManager();
        } elseif ($entityManager !== null) {
            $entityManager->clear();
        }

        $video = $this->videoRepository->find($videoId);
        if ($video === null) {
            return false;
        }

        $reason = mb_substr($reason, 0, 1000);
        $fail = VideoWorkflowProcessTransition::FAIL->value;
        if ($this->workflowMachine->can($video, $fail)) {
            $this->workflowMachine->apply($video, $fail);
        }
        $video->setErrorMessage(sprintf('%s: %s', $step->label(), $reason));
        // failStep speichert auch das Video
        $this->processingService->failStep($video, $step, $reason);

        return true;
    }
}
