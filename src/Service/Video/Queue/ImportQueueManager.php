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
        private readonly LoggerInterface $logger
    ) {}

    public function processQueue(): int
    {
        // 1. Check how many are currently processing
        $allVideos = $this->videoRepository->findAll();
        foreach ($allVideos as $v) {
            $this->logger->info(sprintf('Video %d status: %s', $v->getId(), $v->getStatus()?->value));
        }
        $processingVideos = array_filter($allVideos, fn(Video $v) => $v->getStatus()?->isProcessing() ?? false);
        
        $currentCount = count($processingVideos);
        $this->logger->info(sprintf('ImportQueueManager: %d videos currently processing.', $currentCount));
        
        if ($currentCount >= self::MAX_PARALLEL_PROCESSES) {
            $this->logger->info('ImportQueueManager: Max parallel processes reached.');
            return 0; // Already at limit
        }

        $needed = self::MAX_PARALLEL_PROCESSES - $currentCount;

        // 2. Find QUEUED videos
        $queuedVideos = $this->videoRepository->findBy(['status' => VideoStatus::QUEUED], ['createdAt' => 'ASC'], $needed);
        $this->logger->info(sprintf('ImportQueueManager: Found %d queued videos.', count($queuedVideos)));

        $started = 0;
        foreach ($queuedVideos as $video) {
            $this->logger->info(sprintf('ImportQueueManager: Starting processing for video %d.', $video->getId()));
            // 3. Start processing
            $video->setStatus(VideoStatus::CONVERTING);
            $this->entityManager->flush();
            
            $this->videoProcessMessageDispatcher->dispatchInitialProcessMessage($video);
            $started++;
        }

        return $started;
    }
}
