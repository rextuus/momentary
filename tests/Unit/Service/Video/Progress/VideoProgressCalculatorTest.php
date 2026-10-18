<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Video\Progress;

use App\Entity\Video;
use App\Entity\VideoProcessingStep;
use App\Enum\VideoStatus;
use App\Service\Video\Progress\StepDurationEstimator;
use App\Service\Video\Progress\StepProgress;
use App\Service\Video\Progress\VideoProgressCalculator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class VideoProgressCalculatorTest extends TestCase
{
    private const string NOW = '2026-10-18 12:00:00';

    public function testCountedStepUsesItemsInsteadOfTime(): void
    {
        $video = $this->videoIn(VideoStatus::ANALYZING_FACES_INITIAL, startedSecondsAgo: 30);
        $video->recordProgressItem(VideoStatus::ANALYZING_FACES_INITIAL, 3);

        $progress = $this->calculator()->calculate($video);

        self::assertNotNull($progress->current);
        self::assertTrue($progress->current->counted);
        self::assertSame(25, $progress->current->percent());
        self::assertSame('1 von 4 Frames', $progress->current->detail);
        // 1 Frame in 30 s -> 3 Frames noch ca. 90 s, plus 9 ausstehende Schritte à 10 s
        self::assertSame(90 + 90, $progress->remainingSeconds);
    }

    public function testTimeBasedStepMovesBeforeFirstItemIsDone(): void
    {
        $video = $this->videoIn(VideoStatus::ANALYZING_FACES_INITIAL, startedSecondsAgo: 5);

        $progress = $this->calculator()->calculate($video);

        self::assertNotNull($progress->current);
        self::assertFalse($progress->current->counted);
        self::assertSame(45, $progress->current->percent(), 'halbe erwartete Dauer -> 45 %');
    }

    public function testOverdueStepApproachesButNeverReachesHundred(): void
    {
        $video = $this->videoIn(VideoStatus::CONVERTING, startedSecondsAgo: 10_000);

        $current = $this->calculator()->calculate($video)->current;

        self::assertNotNull($current);
        self::assertGreaterThan(90, $current->percent());
        self::assertLessThan(100, $current->percent());
    }

    public function testOverallPercentWeightsStepsByExpectedDuration(): void
    {
        // 14 Schritte à 10 s; die ersten 7 fertig, der 8. läuft gerade an
        $video = $this->videoIn(VideoStatus::ANALYZING_FACES_REFINEMENT, startedSecondsAgo: 0);

        $progress = $this->calculator()->calculate($video);

        self::assertSame(50, $progress->percent);
        self::assertSame(StepProgress::DONE, $progress->steps[0]->state);
        self::assertSame(StepProgress::ACTIVE, $progress->steps[7]->state);
        self::assertSame(StepProgress::PENDING, $progress->steps[8]->state);
    }

    public function testFailedVideoMarksFailedStep(): void
    {
        $video = new Video();
        $video->setStatus(VideoStatus::ERROR);
        $video->addProcessingStep(
            new VideoProcessingStep()
                ->setStep(VideoStatus::CONVERTING)
                ->setFinishedAt(new \DateTimeImmutable(self::NOW))
                ->setDuration(65),
        );
        $video->addProcessingStep(
            new VideoProcessingStep()
                ->setStep(VideoStatus::SCENE_DETECTION)
                ->setErrorMessage('kaputt'),
        );

        $progress = $this->calculator()->calculate($video);

        self::assertNull($progress->current);
        self::assertNull($progress->remainingSeconds);
        self::assertSame('fertig in 1:05 min', $progress->steps[0]->detail);
        self::assertSame(StepProgress::ERROR, $progress->steps[1]->state);
        self::assertSame(StepProgress::PENDING, $progress->steps[2]->state);
    }

    public function testCompletedVideoIsHundredPercent(): void
    {
        $video = new Video();
        $video->setStatus(VideoStatus::COMPLETED);

        self::assertSame(100, $this->calculator()->calculate($video)->percent);
    }

    private function videoIn(VideoStatus $status, int $startedSecondsAgo): Video
    {
        $video = new Video();
        $video->setStatus($status);
        $video->addProcessingStep(
            new VideoProcessingStep()
                ->setStep($status)
                ->setStartedAt(new \DateTimeImmutable(self::NOW)->modify(sprintf('-%d seconds', $startedSecondsAgo))),
        );

        return $video;
    }

    private function calculator(): VideoProgressCalculator
    {
        $estimator = $this->createStub(StepDurationEstimator::class);
        $estimator->method('expectedSeconds')->willReturn(10.0);

        return new VideoProgressCalculator($estimator, new MockClock(self::NOW));
    }
}
