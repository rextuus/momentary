<?php

declare(strict_types=1);

namespace App\Tests\Functional\Event;

use App\Entity\Event;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\PhotoFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventTest extends WebTestCase
{
    public function testNewEventCollectsVisibleMediaFromItsPeriod(): void
    {
        $client = static::createClient();
        $me = UserFactory::createOne();
        $someoneElse = UserFactory::createOne();
        $at = static fn(string $berlinTime): \DateTimeImmutable => new \DateTimeImmutable(
            $berlinTime,
            new \DateTimeZone('Europe/Berlin'),
        )->setTimezone(new \DateTimeZone('UTC'));

        $myVideo = VideoFactory::new()->completed()->create(['owner' => $me, 'takenAt' => $at('2025-03-19 22:23')]);
        $publicPhoto = PhotoFactory::new()
            ->completed()
            ->public()
            ->ownedBy($someoneElse)
            ->create(['takenAt' => $at('2025-03-20 00:30')]);
        $lastMinute = PhotoFactory::new()
            ->completed()
            ->ownedBy($me)
            ->create(['takenAt' => $at('2025-03-21 23:59')]);
        PhotoFactory::new()
            ->completed()
            ->ownedBy($me)
            ->create(['takenAt' => $at('2025-03-22 00:01')]);
        PhotoFactory::new()
            ->completed()
            ->ownedBy($someoneElse)
            ->create(['takenAt' => $at('2025-03-20 12:00')]);

        $client->loginUser($me);
        $client->request('GET', '/event/new');
        $client->submitForm('Speichern', [
            'event[title]' => 'Mallorca 2025',
            'event[startDate]' => '2025-03-19',
            'event[endDate]' => '2025-03-21',
        ]);

        self::assertResponseRedirects();
        $event = static::getContainer()
            ->get(EntityManagerInterface::class)
            ->getRepository(Event::class)
            ->findOneBy(['title' => 'Mallorca 2025']);
        self::assertNotNull($event);
        self::assertEqualsCanonicalizing(
            [$myVideo->getId(), $publicPhoto->getId(), $lastMinute->getId()],
            array_map(static fn($m) => $m->getId(), $event->getMedia()->toArray()),
            'Zeitraum in deutscher Zeit, inklusive letztem Tag; fremde private Fotos bleiben außen vor',
        );

        $client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '3 Medien hinzugefügt');
        self::assertSelectorTextContains('h1', 'Mallorca 2025');
    }

    public function testOnlyOwnerOrAdminMayEditEvents(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne();
        $event = EventFactory::createOne(['owner' => $owner, 'title' => 'Hochzeit']);
        $hidden = EventFactory::createOne(['owner' => $owner, 'title' => 'Privat']);
        $event->addMedium(PhotoFactory::new()->public()->ownedBy($owner)->create());
        static::getContainer()->get('doctrine')->getManager()->flush();

        $client->loginUser(UserFactory::createOne());
        $client->request('GET', '/event/' . $hidden->getId());
        self::assertResponseStatusCodeSame(404, 'nichts Sichtbares darin: für andere nicht da');
        $client->request('GET', '/event/');
        self::assertSelectorTextContains('main, body', 'Hochzeit');
        self::assertSelectorTextNotContains('main, body', 'Privat');
        $client->request('GET', '/event/' . $event->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href$="/edit"]');
        $client->request('GET', '/event/' . $event->getId() . '/edit');
        self::assertResponseStatusCodeSame(403);

        $client->loginUser(UserFactory::new()->admin()->create());
        $client->request('GET', '/event/' . $event->getId() . '/edit');
        self::assertResponseIsSuccessful();
    }

    public function testAddPhotoFromPhotoPageAndRemoveItAgain(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne();
        $photo = PhotoFactory::new()->completed()->ownedBy($owner)->create(['title' => 'Torte']);
        $event = EventFactory::createOne(['owner' => $owner, 'title' => 'Geburtstag']);

        $client->loginUser($owner);
        $client->request('GET', '/photo/' . $photo->getId());
        $client->submitForm('Hinzufügen', ['event' => (string) $event->getId()]);
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Zu „Geburtstag“ hinzugefügt');

        $crawler = $client->request('GET', '/event/' . $event->getId());
        self::assertSelectorTextContains('#event-media', 'Torte');
        $client->submit($crawler->filter('form[action*="/remove/"]')->form());

        $client->followRedirect();
        self::assertSelectorTextContains('#event-media', 'Noch keine Medien');
    }

    public function testVisitorsOnlySeeMediaTheyMayView(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne();
        $event = EventFactory::createOne(['owner' => $owner]);
        $event->addMedium(PhotoFactory::new()->completed()->public()->create(['title' => 'Für alle']));
        $event->addMedium(PhotoFactory::new()->completed()->ownedBy($owner)->create(['title' => 'Nur für mich']));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser(UserFactory::createOne());
        $client->request('GET', '/event/' . $event->getId());

        self::assertSelectorTextContains('#event-media', 'Für alle');
        self::assertSelectorTextNotContains('#event-media', 'Nur für mich');
    }
}
