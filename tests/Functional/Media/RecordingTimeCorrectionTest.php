<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Entity\CameraClockOffset;
use App\Entity\Event;
use App\Entity\Medium;
use App\Service\Photo\PhotoSearchIndex;
use App\Service\VideoSearchIndex;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\PhotoFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Falsch gestellte Kamera-Uhr: eine Aufnahme richtigstellen, das ganze Ereignis wandert mit.
 */
final class RecordingTimeCorrectionTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set(PhotoSearchIndex::class, $this->createStub(PhotoSearchIndex::class));
        static::getContainer()->set(VideoSearchIndex::class, $this->createStub(VideoSearchIndex::class));
    }

    private static function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    public function testShiftsWholeEventByTheSameOffset(): void
    {
        $me = UserFactory::createOne();
        $first = VideoFactory::createOne(['owner' => $me])->setRecordedAt(
            self::utc('2025-03-19 11:15:37'),
            Medium::DATE_FILENAME,
        );
        $photo = PhotoFactory::new()->ownedBy($me)->create(['takenAt' => self::utc('2025-03-19 12:35:38')]);
        $lastNight = VideoFactory::createOne(['owner' => $me, 'takenAt' => self::utc('2025-03-20 00:10:05')]);
        $foreign = PhotoFactory::new()
            ->public()
            ->ownedBy(UserFactory::createOne())
            ->create(['takenAt' => self::utc('2025-03-19 15:00')]);
        $event = EventFactory::createOne([
            'owner' => $me,
            'title' => 'Mallorca',
            'startDate' => new \DateTimeImmutable('2025-03-19'),
            'endDate' => new \DateTimeImmutable('2025-03-20'),
        ]);
        foreach ([$first, $photo, $lastNight, $foreign] as $medium) {
            $event->addMedium($medium);
        }
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->loginUser($me);
        $this->client->request('GET', '/event/' . $event->getId());
        // Erstes Medium tatsächlich am 25.05.2026 um 12:00 deutscher Sommerzeit
        $this->client->submitForm('Alle verschieben', ['recorded_at' => '2026-05-25T12:00']);

        self::assertResponseRedirects('/event/' . $event->getId());
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $date = static fn(Medium $m): string => (string) $entityManager
            ->find(Medium::class, $m->getId())
            ?->getTakenAt()
            ?->format('Y-m-d H:i:s');

        self::assertSame('2026-05-25 10:00:00', $date($first));
        self::assertSame('2026-05-25 11:20:01', $date($photo), 'Abstand zum ersten Medium bleibt');
        self::assertSame('2026-05-25 22:54:28', $date($lastNight));
        self::assertSame('2025-03-19 15:00:00', $date($foreign), 'fremde Medien bleiben unverändert');
        self::assertSame(Medium::DATE_MANUAL, $entityManager->find(Medium::class, $photo->getId())?->getDateSource());

        $event = $entityManager->find(Event::class, $event->getId());
        self::assertSame(
            ['2025-03-19', '2026-05-26'],
            [$event?->getStartDate()?->format('Y-m-d'), $event?->getEndDate()?->format('Y-m-d')],
            'Zeitraum passt sich an (inkl. fremdem Medium)',
        );

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '3 Medien von „Mallorca“ verschoben');
    }

    public function testSingleMediumFromItsPage(): void
    {
        $me = UserFactory::createOne();
        $photo = PhotoFactory::new()
            ->completed()
            ->ownedBy($me)
            ->create()
            ->setRecordedAt(self::utc('2025-03-19 12:35:38'), Medium::DATE_EXIF);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/photo/' . $photo->getId());
        self::assertSelectorTextContains('.recorded-at-editor', 'Nur dieses Foto');
        self::assertStringContainsString('laut Kamera (EXIF)', $crawler->filter('h1 + div')->text());

        $this->client->submitForm('Übernehmen', ['recorded_at' => '2026-05-24T18:30']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('h1 + div', '24.05.2026, 18:30');
        self::assertSelectorTextContains('h1 + div', 'von Hand korrigiert');
    }

    public function testOnlyEventOwnerMayShiftTheWholeEvent(): void
    {
        $me = UserFactory::createOne();
        $photo = PhotoFactory::new()->ownedBy($me)->create(['takenAt' => self::utc('2025-03-19 12:00')]);
        $event = EventFactory::createOne(['owner' => UserFactory::createOne()]);
        $event->addMedium($photo);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/photo/' . $photo->getId());
        self::assertCount(
            0,
            $crawler->filter('input[name="event"][value="' . $event->getId() . '"]'),
            'nicht angeboten',
        );

        $token = (string) $crawler->filter('.recorded-at-editor input[name="_token"]')->attr('value');
        $this->client->request('POST', '/medium/' . $photo->getId() . '/recorded-at', [
            '_token' => $token,
            'recorded_at' => '2026-05-24T18:30',
            'event' => $event->getId(),
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanRememberTheOffsetAndApplyItToOtherMedia(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $photo = PhotoFactory::new()->completed()->ownedBy($admin)->create(['camera' => 'AKASO EK7000PRO']);
        $photo->setRecordedAt(self::utc('2025-03-19 12:00'), Medium::DATE_EXIF);
        $event = EventFactory::createOne(['owner' => $admin, 'title' => 'Mallorca']);
        $event->addMedium($photo);
        // Gleiche Kamera, nicht im Ereignis (z. B. früher importiert)
        $other = PhotoFactory::new()->completed()->create(['camera' => 'AKASO EK7000PRO']);
        $other->setRecordedAt(self::utc('2025-03-20 09:00'), Medium::DATE_EXIF);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($admin);

        $this->client->request('GET', '/event/' . $event->getId());
        self::assertSelectorTextContains('#event-remember-clock + label', 'AKASO EK7000PRO');
        $this->client->submitForm('Alle verschieben', ['recorded_at' => '2026-05-23T14:00', 'remember' => '1']);
        self::assertSame(
            1,
            static::getContainer()
                ->get(EntityManagerInterface::class)
                ->getRepository(CameraClockOffset::class)
                ->count([]),
        );

        $this->client->request('GET', '/photo/' . $other->getId());
        self::assertSelectorTextContains('.alert-warning', 'Abweichung');

        $this->client->request('GET', '/admin/camera-clocks');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'AKASO EK7000PRO');
        $this->client->submitForm('Auf 1 Medien anwenden');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '1 Medien');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $other = $entityManager->find(Medium::class, $other->getId());
        self::assertSame('2026-05-24 09:00', $other?->getTakenAt()?->format('Y-m-d H:i'));
        self::assertSame(Medium::DATE_CAMERA_OFFSET, $other->getDateSource());
    }

    public function testOnlyAdminsCanRememberAnOffset(): void
    {
        $me = UserFactory::createOne();
        $photo = PhotoFactory::new()->completed()->ownedBy($me)->create(['camera' => 'AKASO EK7000PRO']);
        $photo->setRecordedAt(self::utc('2025-03-19 12:00'), Medium::DATE_EXIF);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($me);

        $crawler = $this->client->request('GET', '/photo/' . $photo->getId());
        self::assertCount(0, $crawler->filter('input[name="remember"]'));
        $this->client->request('POST', '/medium/' . $photo->getId() . '/recorded-at', [
            '_token' => (string) $crawler->filter('.recorded-at-editor input[name="_token"]')->attr('value'),
            'recorded_at' => '2026-05-24T18:30',
            'remember' => '1',
        ]);

        self::assertResponseRedirects();
        self::assertSame(
            0,
            static::getContainer()
                ->get(EntityManagerInterface::class)
                ->getRepository(CameraClockOffset::class)
                ->count([]),
        );
    }
}
