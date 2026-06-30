<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Video;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

class WorkflowMachine
{
    public function __construct(
        #[Target('video_processing')]
        private WorkflowInterface $videoProcessingWorkflow,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    public function can(Video $video, string $transition): bool
    {
        return $this->videoProcessingWorkflow->can($video, $transition);
    }

    public function apply(Video $video, string $transition, array $context = []): void
    {
        try {
            $this->videoProcessingWorkflow->apply($video, $transition, $context);
            $this->entityManager->flush();
            $this->logger->info(sprintf('Transition "%s" applied to video %d', $transition, $video->getId()));
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to apply transition "%s" to video %d: %s', $transition, $video->getId(), $e->getMessage()));
            throw $e;
        }
    }
}
