<?php

declare(strict_types=1);

namespace App\Tests\Functional\Pages;

use App\Entity\TranscriptSegment;
use App\Entity\VideoTranscript;
use App\Enum\PersonStatus;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoChapterFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoFactory;
use App\Tests\Factory\VideoSceneFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Rendert alle Seiten mit realistischen Daten. Fängt vor allem Fehler in Templates und Live Components ab,
 * die erst mit echten Personen, Gesichtern und Szenen auftreten.
 */
final class PageSmokeTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'Dashboard' => ['/'];
        yield 'Videos' => ['/video/'];
        yield 'Video-Detail' => ['/video/{video}'];
        yield 'Timeline' => ['/video/{video}/timeline'];
        yield 'Kapitel' => ['/video/chapter/{chapter}'];
        yield 'Personen' => ['/person/'];
        yield 'Personen gefiltert' => ['/person/?statuses[]=new&statuses[]=identified&sort=name&search=a'];
        yield 'Person-Detail' => ['/person/{person}/detail'];
        yield 'Resolve' => ['/admin/resolve-identities'];
        yield 'Quick Resolve' => ['/admin/quick-resolve'];
        yield 'Tinder Resolve' => ['/admin/tinder-resolve'];
        yield 'Mass Resolve' => ['/admin/mass-face-resolve/{person}'];
        yield 'Auftrennen' => ['/admin/reassign-faces'];
        yield 'Gesichtserkennung' => ['/admin/settings/face-recognition'];
        yield 'Transkription' => ['/admin/settings/transcription'];
    }

    #[DataProvider('pages')]
    public function testPageRendersWithData(string $url): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::new()->admin()->create());

        $video = VideoFactory::new()->completed()->public()->create(['title' => 'Urlaub']);
        $scene = VideoSceneFactory::createOne(['video' => $video, 'startSeconds' => 0.0, 'endSeconds' => 30.0]);
        $chapter = VideoChapterFactory::createOne(['video' => $video, 'startSeconds' => 0.0, 'endSeconds' => 30.0]);
        $anna = PersonFactory::createOne([
            'name' => 'Anna',
            'status' => PersonStatus::IDENTIFIED,
            'identified' => true,
        ]);
        $unknown = PersonFactory::createOne(['name' => 'unknown_abc', 'status' => PersonStatus::NEW]);
        foreach ([$anna, $unknown] as $i => $person) {
            VideoFaceFactory::createOne([
                'video' => $video,
                'videoScene' => $scene,
                'person' => $person,
                'faceImage' => FileFactory::new(),
                'timestamp' => 5 + $i,
                'boundingBox' => ['Left' => 0.2, 'Top' => 0.2, 'Width' => 0.1, 'Height' => 0.15],
            ]);
        }

        $transcript = new VideoTranscript($video);
        $transcript->start('large-v3-turbo', 30.0);
        $transcript->complete('de', [
            new TranscriptSegment(1.0, 4.0, 'Alles Gute zum Geburtstag!'),
            new TranscriptSegment(40.0, 42.0, 'Nach der letzten Szene', -1.2),
        ]);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($transcript);
        $entityManager->flush();

        $client->request('GET', strtr($url, [
            '{video}' => (string) $video->getId(),
            '{chapter}' => (string) $chapter->getId(),
            '{person}' => (string) $unknown->getId(),
        ]));

        self::assertResponseIsSuccessful();
    }
}
