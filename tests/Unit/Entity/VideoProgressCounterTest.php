<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Video;
use App\Enum\VideoStatus;
use PHPUnit\Framework\TestCase;

final class VideoProgressCounterTest extends TestCase
{
    public function testCountsItemsOfCurrentStep(): void
    {
        $video = new Video();
        $video->recordProgressItem(VideoStatus::ANALYZING_FACES_INITIAL, 9);
        $video->recordProgressItem(VideoStatus::ANALYZING_FACES_INITIAL, 8);

        self::assertSame(['done' => 2, 'total' => 10], $video->getProgressFor(VideoStatus::ANALYZING_FACES_INITIAL));
        self::assertNull($video->getProgressFor(VideoStatus::TAGGING_SCENES));
    }

    public function testNewStepStartsCountingFromZero(): void
    {
        $video = new Video();
        $video->recordProgressItem(VideoStatus::ANALYZING_FACES_INITIAL, 0);
        $video->recordProgressItem(VideoStatus::TAGGING_SCENES, 3);

        self::assertNull($video->getProgressFor(VideoStatus::ANALYZING_FACES_INITIAL));
        self::assertSame(['done' => 1, 'total' => 4], $video->getProgressFor(VideoStatus::TAGGING_SCENES));
    }

    public function testResetForgetsCounter(): void
    {
        $video = new Video();
        $video->recordProgressItem(VideoStatus::TAGGING_SCENES, 3);
        $video->resetProgress();

        self::assertNull($video->getProgressFor(VideoStatus::TAGGING_SCENES));
    }
}
