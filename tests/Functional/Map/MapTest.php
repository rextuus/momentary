<?php

declare(strict_types=1);

namespace App\Tests\Functional\Map;

use App\Tests\Factory\EventFactory;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\PhotoFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MapTest extends WebTestCase
{
    public function testPointsContainOnlyVisibleMediaWithLocation(): void
    {
        $client = static::createClient();
        $me = UserFactory::createOne();
        $photo = PhotoFactory::new()->completed()->ownedBy($me)->create(['title' => 'Strand']);
        $photo->setLocation(39.37, 3.22)->setPlaceName('Cala d\'Or, Spanien');
        $video = VideoFactory::createOne(['owner' => $me, 'title' => 'Hafen']);
        $video->setLocation(39.57, 2.65);
        PhotoFactory::new()->ownedBy($me)->create(['title' => 'Ohne Ort']);
        $foreign = PhotoFactory::new()->ownedBy(UserFactory::createOne())->create(['title' => 'Fremd']);
        $foreign->setLocation(48.1, 11.5);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser($me);
        $client->request('GET', '/map/points');

        self::assertResponseIsSuccessful();
        $points = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertEqualsCanonicalizing(['Strand', 'Hafen'], array_column($points, 'title'));
        $strand = array_values(array_filter($points, static fn($p) => $p['title'] === 'Strand'))[0];
        self::assertSame([39.37, 3.22, 'photo', 'Cala d\'Or, Spanien'], [
            $strand['lat'],
            $strand['lon'],
            $strand['type'],
            $strand['place'],
        ]);
        self::assertSame('/photo/' . $photo->getId(), $strand['url']);
        self::assertStringStartsWith('http://imgproxy.test/', (string) $strand['thumbnail']);

        $client->request('GET', '/map/points?type=video');
        self::assertSame(
            ['Hafen'],
            array_column(json_decode((string) $client->getResponse()->getContent(), true), 'title'),
        );
    }

    public function testFiltersByEventAndPerson(): void
    {
        $client = static::createClient();
        $me = UserFactory::createOne();
        $oma = PersonFactory::createOne(['name' => 'Oma']);
        $withOma = PhotoFactory::new()->ownedBy($me)->create(['title' => 'Mit Oma']);
        $withOma->setLocation(50.0, 8.0);
        $inEvent = PhotoFactory::new()->ownedBy($me)->create(['title' => 'Im Urlaub']);
        $inEvent->setLocation(39.0, 3.0);
        VideoFaceFactory::createOne(['medium' => $withOma, 'person' => $oma]);
        $event = EventFactory::createOne(['owner' => $me]);
        $event->addMedium($inEvent);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser($me);
        $client->request('GET', '/map/points?event=' . $event->getId());
        self::assertSame(
            ['Im Urlaub'],
            array_column(json_decode((string) $client->getResponse()->getContent(), true), 'title'),
        );

        $client->request('GET', '/map/points?person=' . $oma->getId());
        self::assertSame(
            ['Mit Oma'],
            array_column(json_decode((string) $client->getResponse()->getContent(), true), 'title'),
        );

        $client->request('GET', '/event/' . $event->getId());
        self::assertSelectorExists(
            '[data-controller="media-map"][data-media-map-url-value$="event=' . $event->getId() . '"]',
        );
    }

    public function testMapPageAndEmbeddedMaps(): void
    {
        $client = static::createClient();
        $me = UserFactory::createOne();
        $photo = PhotoFactory::new()->completed()->ownedBy($me)->create();
        $photo->setLocation(39.37, 3.22)->setPlaceName('Cala d\'Or, Spanien');
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser($me);
        $client->request('GET', '/map?type=photo');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-media-map-url-value="/map/points?type=photo"]');

        $client->request('GET', '/photo/' . $photo->getId());
        self::assertSelectorTextContains('h1 + div', 'Cala d\'Or, Spanien');
        self::assertSelectorExists('[data-media-map-url-value="/map/points?medium=' . $photo->getId() . '"]');
    }
}
