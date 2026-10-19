<?php

declare(strict_types=1);

namespace App\Tests\Functional\Pool;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Enum\FilePurpose;
use App\Enum\PersonStatus;
use App\Service\Photo\PhotoSearchIndex;
use App\Service\Storage\StoragePathProvider;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\PhotoFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Admin legt Konto und Pool an, die Mutter sieht genau den Pool.
 */
final class PoolWorkflowTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set(PhotoSearchIndex::class, $this->createStub(PhotoSearchIndex::class));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove(
            static::getContainer()->get(StoragePathProvider::class)->getAbsoluteFilePath('videos/pool-test'),
        );
        parent::tearDown();
    }

    public function testAdminSharesHomeVideosWithMotherWhoSeesOnlyThem(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $homeTag = TagFactory::createOne(['name' => 'Heimvideos']);
        $oma = PersonFactory::createOne(['name' => 'Oma', 'status' => PersonStatus::IDENTIFIED]);
        $friend = PersonFactory::createOne(['name' => 'Kumpel', 'status' => PersonStatus::IDENTIFIED]);
        $home = VideoFactory::new()->completed()->create([
            'title' => 'Weihnachten 1998',
            'owner' => $admin,
            'takenAt' => new \DateTimeImmutable('1998-12-24 18:00'),
        ]);
        $home->addTag($homeTag);
        $later = VideoFactory::new()->completed()->create([
            'title' => 'Heimvideo 2015',
            'owner' => $admin,
            'takenAt' => new \DateTimeImmutable('2015-06-01'),
        ]);
        $later->addTag($homeTag);
        $trip = PhotoFactory::new()
            ->completed()
            ->ownedBy($admin)
            ->create(['title' => 'Mallorca Pool', 'takenAt' => new \DateTimeImmutable('2026-05-23')]);
        VideoFaceFactory::createOne(['video' => $home, 'person' => $oma]);
        VideoFaceFactory::createOne(['medium' => $trip, 'video' => null, 'person' => $friend]);
        $mallorca = EventFactory::createOne(['owner' => $admin, 'title' => 'Mallorca 2026']);
        $mallorca->addMedium($trip);
        $playable = 'videos/pool-test/converted.mp4';
        new Filesystem()->dumpFile(
            static::getContainer()->get(StoragePathProvider::class)->getAbsoluteFilePath($playable),
            'mp4',
        );
        $home->setConvertedFile(FileFactory::createOne([
            'relativePath' => $playable,
            'purpose' => FilePurpose::VIDEO_CONVERTED,
            'mimeType' => 'video/mp4',
        ]));
        $this->em()->flush();
        $this->client->loginUser($admin);

        // 1. Konto für die Mutter
        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler
            ->selectButton('Anlegen')
            ->form(['email' => 'Mama@Example.com', 'role' => 'family']));
        self::assertResponseIsSuccessful();
        $password = (string) $this->client->getCrawler()->filter('input[aria-label="Passwort"]')->attr('value');
        self::assertMatchesRegularExpression('/^[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}$/', $password);
        $mother = $this->em()->getRepository(User::class)->findOneBy(['email' => 'mama@example.com']);
        self::assertNotNull($mother);
        self::assertSame(['ROLE_USER'], $mother->getRoles());

        // 2. Pool mit Regel „Heimvideos bis 2004“ und der Mutter als Mitglied
        $crawler = $this->client->request('GET', '/admin/pools');
        $this->client->submit($crawler
            ->selectButton('Anlegen')
            ->form(['name' => 'Familie', 'description' => 'Alte Heimvideos']));
        $this->client->followRedirect();
        $this->client->submit($this->client
            ->getCrawler()
            ->selectButton('Regel hinzufügen')
            ->form(['tag' => 'Heimvideos', 'until' => '2004-12-31']));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.list-group', 'Tag „Heimvideos“ · aufgenommen bis 31.12.2004');
        $form = $crawler->selectButton('Mitglieder speichern')->form();
        $values = $form->getPhpValues();
        $values['members'] = [(string) $mother->getId()];
        $this->client->request('POST', $form->getUri(), $values);
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1 + div', '1 Medien · 1 Mitglied');

        // 3. Ein Foto einzeln dazu, über die Pool-Karte
        $this->client->request('GET', '/photo/' . $trip->getId());
        self::assertSelectorTextContains('#pools', 'nicht drin');
        $this->client->submit($this->client->getCrawler()->filter('#pools')->selectButton('Aufnehmen')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('#pools', 'drin (einzeln hinzugefügt)');
        $this->client->request('GET', '/video/' . $home->getId());
        self::assertSelectorTextContains('#pools', 'drin (über eine Regel)');

        // 4. Die Mutter meldet sich an
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => 'mama@example.com', '_password' => $password]);
        $this->client->followRedirect();
        self::assertResponseRedirects('/timeline', message: 'Familie startet auf der Zeitachse');
        $crawler = $this->client->followRedirect();
        $titles = $crawler->filter('.timeline-tile')->each(static fn($tile): string => (string) $tile->attr('title'));
        self::assertEqualsCanonicalizing(['Weihnachten 1998', 'Mallorca Pool'], $titles);
        self::assertSelectorNotExists('a[href="/person/"]', 'kein Menüpunkt Personen');
        self::assertSelectorNotExists('a[href="/upload"]');

        $this->client->request('GET', '/video/');
        self::assertSelectorTextContains('body', 'Weihnachten 1998');
        self::assertSelectorTextNotContains('body', 'Heimvideo 2015');

        $this->client->request('GET', '/video/' . $home->getId());
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('/stream/video/' . $home->getId(), $page, 'spielt über die signierte URL');
        self::assertStringNotContainsString('api_key', $page);
        self::assertSelectorNotExists('#pools', 'Familie verwaltet keine Pools');

        $this->client->request('GET', '/video/' . $later->getId());
        self::assertResponseStatusCodeSame(403, 'Heimvideo nach 2004 gehört nicht zum Pool');

        $this->client->request('GET', '/event/');
        self::assertSelectorTextContains('body', 'Mallorca 2026');
        $this->client->request('GET', '/event/' . $mallorca->getId());
        self::assertSelectorTextContains('body', 'Kumpel', 'Personen aus sichtbaren Medien');
        self::assertSelectorNotExists(
            'a[href="/person/' . $friend->getId() . '/detail"]',
            'Link führt zur Zeitachse statt zur Personenseite',
        );

        $this->client->request('GET', '/person/');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/upload');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/pools');
        self::assertResponseStatusCodeSame(403);
    }

    public function testRemovingAMediumMatchedByARuleExcludesIt(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $event = EventFactory::createOne(['owner' => $admin]);
        $photo = PhotoFactory::new()->completed()->ownedBy($admin)->create();
        $event->addMedium($photo);
        $pool = new UserGroup()
            ->setName('Familie')
            ->setOwner($admin);
        $this->em()->persist($pool);
        $pool->addRule(new \App\Entity\PoolRule($pool)->setEvent($event));
        $this->em()->flush();
        $this->client->loginUser($admin);

        $this->client->request('GET', '/photo/' . $photo->getId());
        $this->client->submit($this->client->getCrawler()->filter('#pools')->selectButton('Herausnehmen')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('#pools', 'ausgenommen');

        $this->client->request('GET', '/admin/pools/' . $pool->getId());
        self::assertSelectorTextContains('body', 'Ausgenommen');
        $this->client->submit($this->client->getCrawler()->selectButton('Wieder aufnehmen')->form());
        $this->client->request('GET', '/photo/' . $photo->getId());
        self::assertSelectorTextContains('#pools', 'drin (über eine Regel)');
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
