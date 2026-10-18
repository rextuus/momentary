<?php

declare(strict_types=1);

namespace App\Service\Video;

use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Service\Video\Queue\ImportQueueManager;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stellt Videos erneut in die Verarbeitungs-Queue. Szenen und Gesichter erzeugt die Pipeline dabei neu.
 */
class VideoReprocessor
{
    public function __construct(
        private readonly ImportQueueManager $importQueueManager,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Grund, warum das Video nicht neu verarbeitet werden kann, oder null.
     */
    public function whyNot(Video $video): ?string
    {
        if ($video->getStatus()->isProcessing()) {
            return sprintf('Das Video wird gerade verarbeitet (%s).', $video->getStatus()->value);
        }
        if ($video->getSourceFile() === null) {
            return 'Das Video hat keine Quelldatei mehr.';
        }

        return null;
    }

    /**
     * @param list<Video> $videos
     *
     * @return array{queued: int, started: int}
     */
    public function reprocess(array $videos): array
    {
        $queued = 0;
        foreach ($videos as $video) {
            if ($this->whyNot($video) !== null) {
                continue;
            }
            // Wie beim Import: die Queue startet das Video über ConvertStep neu (Status-Feld ist der Workflow-Marking-Store)
            $video->setStatus(VideoStatus::QUEUED);
            $video->setErrorMessage(null);
            $video->resetProgress();
            // Zeitmessung der Schritte beginnt beim neuen Durchlauf von vorn
            foreach ($video->getProcessingSteps() as $step) {
                $step->setStartedAt(null)->setFinishedAt(null)->setDuration(null)->setErrorMessage(null);
            }
            $queued++;
        }

        $this->entityManager->flush();

        return ['queued' => $queued, 'started' => $queued > 0 ? $this->importQueueManager->processQueue() : 0];
    }
}
