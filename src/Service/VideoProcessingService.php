<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Video;
use App\Entity\VideoProcessingStep;
use App\Enum\VideoStatus;
use App\Repository\VideoProcessingStepRepository;
use Doctrine\ORM\EntityManagerInterface;

class VideoProcessingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VideoProcessingStepRepository $repository
    ) {}

    public function startStep(Video $video, VideoStatus $step): void
    {
        $existingStep = $this->repository->findOneBy(['video' => $video, 'step' => $step]);
        if (!$existingStep) {
            $existingStep = new VideoProcessingStep();
            $existingStep->setVideo($video);
            $existingStep->setStep($step);
            $existingStep->setStartedAt(new \DateTimeImmutable());
            $this->entityManager->persist($existingStep);
        } else {
            if ($existingStep->getStartedAt() === null) {
                $existingStep->setStartedAt(new \DateTimeImmutable());
            }
            $existingStep->setFinishedAt(null);
            $existingStep->setErrorMessage(null);
        }
        $this->entityManager->flush();
    }

    public function finishStep(Video $video, VideoStatus $step): void
    {
        $stepEntity = $this->repository->findOneBy(['video' => $video, 'step' => $step]);
        if ($stepEntity) {
            $stepEntity->setFinishedAt(new \DateTimeImmutable());
            $stepEntity->setErrorMessage(null);
            $this->entityManager->flush();
        }
    }

    public function failStep(Video $video, VideoStatus $step, string $errorMessage): void
    {
        $stepEntity = $this->repository->findOneBy(['video' => $video, 'step' => $step]);
        if (!$stepEntity) {
            $stepEntity = new VideoProcessingStep();
            $stepEntity->setVideo($video);
            $stepEntity->setStep($step);
            $this->entityManager->persist($stepEntity);
        }
        $stepEntity->setErrorMessage($errorMessage);
        $this->entityManager->flush();
    }
}
