<?php

declare(strict_types=1);

namespace App\Tests\Integration\Video\Recording;

use App\Entity\RecordingSegment;
use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Service\Video\Analyze\EmptyScenesMerger;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoFactory;
use App\Tests\Factory\VideoSceneFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Leere Szenen werden nur innerhalb einer Aufnahme zusammengelegt: eine Szene gehört immer zu genau einem Tag.
 */
final class EmptyScenesMergerRecordingTest extends KernelTestCase
{
    public function testEmptySceneIsNotMergedAcrossARecordingBoundary(): void
    {
        $video = $this->videoWithScenes(withBoundary: true);

        self::getContainer()->get(EmptyScenesMerger::class)->mergeEmptyScenes($video);

        // Erste Aufnahme: leere Szene geht in die mit Gesicht; zweite Aufnahme ohne Gesichter wird eine Szene
        self::assertSame([[0.0, 8.0], [8.0, 14.0]], $this->ranges($video));
    }

    public function testWithoutRecordingsEverythingMergesAsBefore(): void
    {
        $video = $this->videoWithScenes(withBoundary: false);

        self::getContainer()->get(EmptyScenesMerger::class)->mergeEmptyScenes($video);

        self::assertSame([[0.0, 14.0]], $this->ranges($video));
    }

    /**
     * Szenen 0–4 leer, 4–8 mit Gesicht, 8–10 leer, 10–14 leer; Aufnahmegrenze bei 8 s.
     */
    private function videoWithScenes(bool $withBoundary): Video
    {
        $video = VideoFactory::createOne(['status' => VideoStatus::MERGING_SCENES]);
        foreach ([[0.0, 4.0], [4.0, 8.0], [8.0, 10.0], [10.0, 14.0]] as $i => [$start, $end]) {
            $scene = VideoSceneFactory::createOne([
                'video' => $video,
                'sceneNumber' => $i + 1,
                'startSeconds' => $start,
                'endSeconds' => $end,
            ]);
            if ($i === 1) {
                VideoFaceFactory::createOne(['video' => $video, 'videoScene' => $scene]);
            }
        }
        if ($withBoundary) {
            $video->replaceRecordingSegments([
                new RecordingSegment(0.0, 8.0, new \DateTimeImmutable('2004-07-12 12:00:00')),
                new RecordingSegment(8.0, 14.0, new \DateTimeImmutable('2005-12-24 17:00:00')),
            ]);
        }
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->flush();
        $entityManager->clear();

        $reloaded = $entityManager->find(Video::class, $video->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    /**
     * @return list<array{float, float}>
     */
    private function ranges(Video $video): array
    {
        $ranges = [];
        foreach ($video->getScenes() as $scene) {
            $ranges[] = [$scene->getStartSeconds(), $scene->getEndSeconds()];
        }
        usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        return $ranges;
    }
}
