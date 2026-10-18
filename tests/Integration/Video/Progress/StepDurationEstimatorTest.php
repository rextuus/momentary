<?php

declare(strict_types=1);

namespace App\Tests\Integration\Video\Progress;

use App\Entity\Video;
use App\Entity\VideoProcessingStep;
use App\Enum\VideoStatus;
use App\Service\Video\Progress\StepDurationEstimator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StepDurationEstimatorTest extends KernelTestCase
{
    public function testLearnsSecondsPerVideoSecondFromFinishedSteps(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // 30 s für 60 s Video und 90 s für 60 s Video -> im Schnitt 1 s pro Videosekunde
        foreach ([30, 90] as $duration) {
            $video = new Video()
                ->setTitle('alt')
                ->setCreatedAt(new \DateTimeImmutable())
                ->setDuration(60.0);
            $video->addProcessingStep(
                new VideoProcessingStep()
                    ->setStep(VideoStatus::SCENE_DETECTION)
                    ->setStartedAt(new \DateTimeImmutable())
                    ->setFinishedAt(new \DateTimeImmutable())
                    ->setDuration($duration),
            );
            $entityManager->persist($video);
        }
        $entityManager->flush();

        $estimator = self::getContainer()->get(StepDurationEstimator::class);
        $newVideo = new Video()->setDuration(120.0);

        self::assertEqualsWithDelta(120.0, $estimator->expectedSeconds($newVideo, VideoStatus::SCENE_DETECTION), 0.01);
        // ohne Erfahrungswerte: Startwert
        self::assertEqualsWithDelta(
            120 * 0.3,
            $estimator->expectedSeconds($newVideo, VideoStatus::TAGGING_SCENES),
            0.01,
        );
    }
}
