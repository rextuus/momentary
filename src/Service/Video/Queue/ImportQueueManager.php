<?php

declare(strict_types=1);

namespace App\Service\Video\Queue;

use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ImportQueueManager
{
    private const MAX_PARALLEL_PROCESSES = 2;

    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoProcessMessageDispatcher $videoProcessMessageDispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function processQueue(): int
    {
        $currentCount = $this->countProcessing();

        if ($currentCount >= self::MAX_PARALLEL_PROCESSES) {
            return 0;
        }

        $needed = self::MAX_PARALLEL_PROCESSES - $currentCount;

        $queuedVideos = $this->videoRepository->findBy(
            ['status' => VideoStatus::QUEUED],
            ['createdAt' => 'ASC'],
            $needed,
        );

        $started = 0;
        foreach ($queuedVideos as $video) {
            // Atomar beanspruchen: verhindert, dass Web-Request und Worker dasselbe Video doppelt starten
            if (!$this->claim($video)) {
                continue;
            }

            $this->entityManager->refresh($video);
            $this->logger->info(sprintf('ImportQueueManager: Starte Verarbeitung für Video %d.', $video->getId()));

            if (!$this->videoProcessMessageDispatcher->dispatchInitialProcessMessage($video)) {
                // Dispatch fehlgeschlagen -> zurück in die Warteschlange
                $this->entityManager
                    ->createQueryBuilder()
                    ->update(Video::class, 'v')
                    ->set('v.status', ':queued')
                    ->where('v.id = :id')
                    ->setParameter('queued', VideoStatus::QUEUED->value)
                    ->setParameter('id', $video->getId())
                    ->getQuery()
                    ->execute();
                $this->entityManager->refresh($video);
                continue;
            }

            $started++;
        }

        return $started;
    }

    private function countProcessing(): int
    {
        $processingStates = array_values(array_map(
            static fn(VideoStatus $s): string => $s->value,
            array_filter(VideoStatus::cases(), static fn(VideoStatus $s): bool => $s->isProcessing()),
        ));

        if ($processingStates === []) {
            return 0;
        }

        return (int) $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(v.id)')
            ->from(Video::class, 'v')
            ->where('v.status IN (:states)')
            ->setParameter('states', $processingStates)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function claim(Video $video): bool
    {
        $affected = $this->entityManager
            ->createQueryBuilder()
            ->update(Video::class, 'v')
            ->set('v.status', ':converting')
            ->where('v.id = :id')
            ->andWhere('v.status = :queued')
            ->setParameter('converting', VideoStatus::CONVERTING->value)
            ->setParameter('queued', VideoStatus::QUEUED->value)
            ->setParameter('id', $video->getId())
            ->getQuery()
            ->execute();

        return $affected === 1;
    }
}
