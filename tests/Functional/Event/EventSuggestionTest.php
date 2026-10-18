<?php

declare(strict_types=1);

namespace App\Tests\Functional\Event;

use App\Entity\Event;
use App\Service\Gemini\GeminiService;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\PhotoFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventSuggestionTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $gemini = $this->createStub(GeminiService::class);
        $gemini->method('suggestEventTitle')->willReturn('Pfingsten am See');
        static::getContainer()->set(GeminiService::class, $gemini);
    }

    private static function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    public function testSuggestsCloseMediaAndCreatesEventWithSuggestedTitle(): void
    {
        $me = UserFactory::createOne();
        $video = VideoFactory::createOne(['owner' => $me, 'takenAt' => self::utc('2026-05-24 09:00')]);
        $photo1 = PhotoFactory::new()
            ->completed()
            ->ownedBy($me)
            ->create(['takenAt' => self::utc('2026-05-24 12:00')]);
        $photo2 = PhotoFactory::new()
            ->completed()
            ->ownedBy($me)
            ->create(['takenAt' => self::utc('2026-05-25 08:00')]);
        // zu wenige für einen Vorschlag
        PhotoFactory::new()
            ->completed()
            ->ownedBy($me)
            ->create(['takenAt' => self::utc('2026-01-01 12:00')]);
        // schon in einem Ereignis
        $inEvent = PhotoFactory::new()
            ->completed()
            ->ownedBy($me)
            ->create(['takenAt' => self::utc('2026-05-24 13:00')]);
        EventFactory::createOne(['owner' => $me])->addMedium($inEvent);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/event/');

        self::assertCount(1, $crawler->filter('.suggestion-card'));
        self::assertSelectorTextContains('.suggestion-card', '24.05.2026 – 25.05.2026');
        self::assertSelectorTextContains('.suggestion-card', '1 Video');
        self::assertSelectorTextContains('.suggestion-card', '2 Fotos');

        $this->client->click($crawler->selectLink('Übernehmen')->link());
        self::assertInputValueSame('event[title]', 'Pfingsten am See');
        self::assertInputValueSame('event[startDate]', '2026-05-24');
        self::assertInputValueSame('event[endDate]', '2026-05-25');

        $this->client->submitForm('Ereignis anlegen');
        self::assertResponseRedirects();
        $event = static::getContainer()
            ->get(EntityManagerInterface::class)
            ->getRepository(Event::class)
            ->findOneBy(['title' => 'Pfingsten am See']);
        self::assertNotNull($event);
        self::assertEqualsCanonicalizing(
            [$video->getId(), $photo1->getId(), $photo2->getId()],
            array_map(static fn($m) => $m->getId(), $event->getMedia()->toArray()),
        );

        $this->client->request('GET', '/event/');
        self::assertSelectorNotExists('.suggestion-card', 'übernommene Medien werden nicht mehr vorgeschlagen');
    }

    public function testDismissedSuggestionStaysHiddenUntilNewMediaArrive(): void
    {
        $me = UserFactory::createOne();
        foreach (['10:00', '11:00', '12:00'] as $time) {
            PhotoFactory::new()
                ->completed()
                ->ownedBy($me)
                ->create(['takenAt' => self::utc('2026-05-24 ' . $time)]);
        }

        $this->client->loginUser($me);
        $this->client->request('GET', '/event/');
        $this->client->submitForm('Verwerfen');
        $this->client->followRedirect();
        self::assertSelectorNotExists('.suggestion-card');

        PhotoFactory::new()
            ->completed()
            ->ownedBy($me)
            ->create(['takenAt' => self::utc('2026-05-24 13:00')]);
        $this->client->request('GET', '/event/');
        self::assertSelectorExists('.suggestion-card');
    }

    public function testOutdatedSuggestionRedirectsWithHint(): void
    {
        $this->client->loginUser(UserFactory::createOne());
        $this->client->request('GET', '/event/suggestion/' . str_repeat('a', 64));

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-warning', 'nicht mehr aktuell');
    }
}
