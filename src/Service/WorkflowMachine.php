<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Video;
use App\Enum\VideoStatus;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

readonly class WorkflowMachine
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

    /**
     * @throws Exception
     */
    public function apply(Video $video, string $transition, array $context = []): void
    {
        try {
            $this->videoProcessingWorkflow->apply($video, $transition, $context);
            $this->entityManager->persist($video);
            $this->entityManager->flush();
        } catch (Exception $e) {
            throw $e;
        }
    }
}
