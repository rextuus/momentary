<?php

declare(strict_types=1);

namespace App\Tests\Integration\Video\Recording;

use App\Entity\RecordingSegment;
use App\Entity\Video;
use App\Service\Gemini\GeminiService;
use App\Service\Video\Analyze\ChapterGenerator;
use App\Tests\Factory\VideoFactory;
use App\Tests\Factory\VideoSceneFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ein Kapitel gehört zu genau einer Aufnahme: Schlägt Gemini eins über eine Aufnahmegrenze vor, wird es geteilt.
 */
final class ChapterRecordingTest extends KernelTestCase
{
    public function testChapterSpanningTwoRecordingsIsSplit(): void
    {
        $gemini = $this->createStub(GeminiService::class);
        $gemini
            ->method('suggestChapters')
            ->willReturn([
                ['title' => 'Familienfeier', 'startSeconds' => 0, 'endSeconds' => 14, 'description' => 'Kuchen'],
            ]);
        self::getContainer()->set(GeminiService::class, $gemini);

        $video = VideoFactory::createOne();
        VideoSceneFactory::createOne(['video' => $video, 'startSeconds' => 0.0, 'endSeconds' => 14.0]);
        $video->replaceRecordingSegments([
            new RecordingSegment(0.0, 8.0, new \DateTimeImmutable('2004-07-12 12:00:00')),
            new RecordingSegment(8.0, 14.0, new \DateTimeImmutable('2005-12-24 17:00:00')),
        ]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->flush();

        self::getContainer()->get(ChapterGenerator::class)->generateChapters($video);

        $entityManager->clear();
        $reloaded = $entityManager->find(Video::class, $video->getId());
        self::assertNotNull($reloaded);
        $chapters = [];
        foreach ($reloaded->getChapters() as $chapter) {
            $chapters[] = [$chapter->getTitle(), $chapter->getStartSeconds(), $chapter->getEndSeconds()];
        }
        usort($chapters, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
        self::assertSame(
            [
                ['Familienfeier', 0.0, 8.0],
                ['Familienfeier', 8.0, 14.0],
            ],
            $chapters,
        );
    }
}
