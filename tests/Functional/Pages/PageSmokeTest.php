<?php

declare(strict_types=1);

namespace App\Tests\Functional\Pages;

use App\Entity\TranscriptSegment;
use App\Entity\VideoTranscript;
use App\Enum\PersonStatus;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\PhotoFactory;
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
        yield 'Doppelte Personen' => ['/admin/person-duplicates'];
        yield 'Gesichtserkennung' => ['/admin/settings/face-recognition'];
        yield 'Transkription' => ['/admin/settings/transcription'];
        yield 'Kamera-Uhren' => ['/admin/camera-clocks'];
        yield 'API-Zugänge' => ['/admin/settings/api-clients'];
        yield 'Suche' => ['/search?q=Strand&tags[]=Pool'];
        yield 'Zeitachse' => ['/timeline'];
        yield 'Karte' => ['/map?type=photo'];
        yield 'Kartenpunkte' => ['/map/points?event={event}'];
        yield 'Zeitachse gefiltert' => ['/timeline?type=photo&person={anna}&before=1742380000.99'];
        yield 'Fotos' => ['/photo/'];
        yield 'Fotos gesucht' => ['/photo/?q=Strand&page=1'];
        yield 'Foto-Detail' => ['/photo/{photo}'];
        yield 'Upload' => ['/upload'];
        yield 'Freigaben' => ['/share'];
        yield 'Import-Ordner' => ['/admin/import'];
        yield 'Ereignisse' => ['/event/'];
        yield 'Ereignisse mit Lücke' => ['/event/?gap=12'];
        yield 'Ereignis-Detail' => ['/event/{event}'];
        yield 'Ereignis anlegen' => ['/event/new'];
        yield 'Ereignis bearbeiten' => ['/event/{event}/edit'];
        yield 'Person mit Foto' => ['/person/{anna}/detail'];
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

        // Foto mit erkanntem Gesicht und ein Ereignis mit Video und Foto
        $photo = PhotoFactory::new()->completed()->create([
            'title' => 'Am Strand',
            'takenAt' => new \DateTimeImmutable('2025-03-19 12:00'),
        ]);
        VideoFaceFactory::createOne([
            'medium' => $photo,
            'person' => $anna,
            'faceImage' => $photo->getDisplayFile(),
            'faceLabel' => 'anna-face',
            'timestamp' => 0,
            'boundingBox' => ['Left' => 0.4, 'Top' => 0.2, 'Width' => 0.1, 'Height' => 0.15],
        ]);
        // Unbenannte Person, die Anna ähnelt: Doppelte Personen und „Wer ist dabei?“
        VideoFaceFactory::createOne([
            'medium' => $photo,
            'person' => $unknown,
            'faceImage' => FileFactory::new(),
            'faceLabel' => 'unknown-face',
            'matchCandidates' => ['anna-face' => 88.0],
            'boundingBox' => ['Left' => 0.1, 'Top' => 0.2, 'Width' => 0.1, 'Height' => 0.15],
        ]);
        $event = EventFactory::createOne(['title' => 'Mallorca', 'startDate' => new \DateTimeImmutable('2025-03-19')]);
        $event->addMedium($video);
        $event->addMedium($photo);
        $entityManager->flush();

        $client->request('GET', strtr($url, [
            '{video}' => (string) $video->getId(),
            '{chapter}' => (string) $chapter->getId(),
            '{person}' => (string) $unknown->getId(),
            '{anna}' => (string) $anna->getId(),
            '{photo}' => (string) $photo->getId(),
            '{event}' => (string) $event->getId(),
        ]));

        self::assertResponseIsSuccessful();
    }
}
