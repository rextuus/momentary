<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Video;
use App\Entity\VideoProcessingStep;
use App\Enum\VideoStatus;
use App\Repository\VideoProcessingStepRepository;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

readonly class VideoProcessingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VideoProcessingStepRepository $repository
    ) {}

    public function startStep(Video $video, VideoStatus $step, VideoProcessStepMessageInterface $message): void
    {
        $info = 'Started';
        if ($message->isIntermediateStep()){
            $info = 'Go on with next part of';
        }

        $logMsg = sprintf(
            '[➜] %s step |%s|%s| for video "%s"',
            $info,
            $message->getMessageLoggingIdent(),
            $step->value,
            $video->getTitle()
        );
        echo $logMsg . PHP_EOL;

        if (!$message->isIntermediateStep()){
            $existingStep = $this->repository->findOneBy(['video' => $video, 'step' => $step]);
            if (!$existingStep) {
                $existingStep = new VideoProcessingStep();
                $existingStep->setVideo($video);
                $existingStep->setStep($step);
                $existingStep->setStartedAt(new DateTimeImmutable());
                $this->entityManager->persist($existingStep);
            } else {
                if ($existingStep->getStartedAt() === null) {
                    $existingStep->setStartedAt(new DateTimeImmutable());
                }
                $existingStep->setFinishedAt(null);
                $existingStep->setErrorMessage(null);
            }
            $this->entityManager->flush();
        }
    }

    public function finishStep(Video $video, VideoStatus $step, VideoProcessStepMessageInterface $message): void
    {
        $info = 'Finished';
        if ($message->isIntermediateStep()){
            $info = 'Go on with next part of';
        }

        $logMsg = sprintf(
            '[✓] %s step |%s|%s| for video "%s"',
            $info,
            $message->getMessageLoggingIdent(),
            $step->value,
            $video->getTitle()
        );
        echo $logMsg . PHP_EOL . PHP_EOL;

        if(!$message->isIntermediateStep()){
            $stepEntity = $this->repository->findOneBy(['video' => $video, 'step' => $step]);
            if ($stepEntity) {
                $finishedAt = new DateTimeImmutable();
                $stepEntity->setFinishedAt($finishedAt);
                if ($stepEntity->getStartedAt()) {
                    $stepEntity->setDuration($finishedAt->getTimestamp() - $stepEntity->getStartedAt()->getTimestamp());
                }
                $stepEntity->setErrorMessage(null);
                $this->entityManager->flush();
            }
        }
    }

    public function failStep(Video $video, VideoStatus $step, string $errorMessage): void
    {
        $logMsg = sprintf(
            '[⚠] Processing for video "%s" ended with error in step |%s|',
            $video->getTitle(),
            $step->value
        );
        echo $logMsg . PHP_EOL;

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
