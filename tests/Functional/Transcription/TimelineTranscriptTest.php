<?php

declare(strict_types=1);

namespace App\Tests\Functional\Transcription;

use App\Entity\TranscriptSegment;
use App\Entity\Video;
use App\Entity\VideoTranscript;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoChapterFactory;
use App\Tests\Factory\VideoFactory;
use App\Tests\Factory\VideoSceneFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TimelineTranscriptTest extends WebTestCase
{
    public function testSpeechTrackAndSpokenTextPerSceneWithoutChapters(): void
    {
        $client = static::createClient();
        $video = VideoFactory::new()->completed()->create(['duration' => 100.0]);
        VideoSceneFactory::createOne([
            'video' => $video,
            'sceneNumber' => 1,
            'startSeconds' => 0.0,
            'endSeconds' => 50.0,
        ]);
        VideoSceneFactory::createOne([
            'video' => $video,
            'sceneNumber' => 2,
            'startSeconds' => 50.0,
            'endSeconds' => 100.0,
        ]);
        $this->transcribe($video, [
            new TranscriptSegment(10.0, 20.0, 'Happy Birthday, Lisa!'),
            new TranscriptSegment(75.0, 80.0, 'Ab in den Pool!'),
        ]);

        $client->loginUser(UserFactory::new()->admin()->create());
        $crawler = $client->request('GET', '/video/' . $video->getId() . '/timeline');

        self::assertResponseIsSuccessful();
        $blocks = $crawler->filter('.speech-block');
        self::assertCount(2, $blocks);
        self::assertStringContainsString('left: 10%; width: 10%;', (string) $blocks->first()->attr('style'));
        self::assertStringEndsWith('?t=75', (string) $blocks->last()->attr('href'));

        $spoken = $crawler->filter('.spoken');
        self::assertCount(2, $spoken, 'jede Szene zeigt ihr Gesprochenes');
        self::assertStringContainsString('Happy Birthday, Lisa!', $spoken->eq(0)->text());
        self::assertStringNotContainsString('Pool', $spoken->eq(0)->text());
        self::assertStringContainsString('Ab in den Pool!', $spoken->eq(1)->text());
    }

    public function testSpokenTextPerChapter(): void
    {
        $client = static::createClient();
        $video = VideoFactory::new()->completed()->create(['duration' => 60.0]);
        VideoSceneFactory::createOne(['video' => $video, 'startSeconds' => 0.0, 'endSeconds' => 60.0]);
        VideoChapterFactory::createOne([
            'video' => $video,
            'title' => 'Geburtstag',
            'startSeconds' => 0.0,
            'endSeconds' => 60.0,
        ]);
        $this->transcribe($video, [new TranscriptSegment(5.0, 7.0, 'Alles Gute!')]);

        $client->loginUser(UserFactory::new()->admin()->create());
        $client->request('GET', '/video/' . $video->getId() . '/timeline');

        self::assertSelectorTextContains('.chapter-card .spoken', 'Alles Gute!');
    }

    public function testNoTrackWithoutTranscript(): void
    {
        $client = static::createClient();
        $video = VideoFactory::new()->completed()->create();
        VideoSceneFactory::createOne(['video' => $video, 'startSeconds' => 0.0, 'endSeconds' => 10.0]);

        $client->loginUser(UserFactory::new()->admin()->create());
        $client->request('GET', '/video/' . $video->getId() . '/timeline');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.speech-track');
        self::assertSelectorNotExists('.spoken');
    }

    /**
     * @param list<TranscriptSegment> $segments
     */
    private function transcribe(Video $video, array $segments): void
    {
        $transcript = new VideoTranscript($video);
        $transcript->start('large-v3-turbo', (float) $video->getDuration());
        $transcript->complete('de', $segments);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($transcript);
        $entityManager->flush();
        $entityManager->clear();
    }
}
