<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Video\Recording;

use App\Entity\VideoScene;
use App\Service\Video\Recording\RecordingGroups;
use App\Service\Video\VideoSceneService;
use PHPUnit\Framework\TestCase;

/**
 * Aufnahmegrenzen sind sichere Schnitte; leere Szenen werden nie über sie hinweg zusammengelegt.
 */
final class RecordingBoundariesTest extends TestCase
{
    public function testSceneSpanningABoundaryIsSplitThere(): void
    {
        self::assertSame(
            [[0.0, 4.0], [4.0, 10.0], [10.0, 12.0], [12.0, 20.0]],
            VideoSceneService::splitAtBoundaries([[0.0, 10.0], [10.0, 20.0]], [4.0, 12.0]),
        );
    }

    public function testCutCloseToTheBoundaryIsTakenAsTheSameCut(): void
    {
        self::assertSame(
            [[0.0, 10.0], [10.0, 20.0]],
            VideoSceneService::splitAtBoundaries([[0.0, 10.0], [10.0, 20.0]], [10.3]),
        );
    }

    public function testScenesAreGroupedPerRecording(): void
    {
        $scenes = [self::scene(0, 4), self::scene(4, 9.8), self::scene(9.8, 15), self::scene(15, 20)];

        $groups = RecordingGroups::scenes($scenes, [10.0]);

        self::assertSame([2, 2], array_map(count(...), $groups), 'Szene 9,8–15 liegt überwiegend nach der Grenze');
        self::assertSame([$scenes], RecordingGroups::scenes($scenes, []), 'ohne Segmente eine Gruppe');
    }

    private static function scene(float $start, float $end): VideoScene
    {
        return new VideoScene()
            ->setStartSeconds($start)
            ->setEndSeconds($end);
    }
}
