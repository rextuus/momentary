<?php

declare(strict_types=1);

namespace App\Tests\Functional\Timeline;

use App\Tests\Factory\PhotoFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TimelinePageTest extends WebTestCase
{
    public function testShowsMonthsDaysAndLoadsMorePartially(): void
    {
        $client = static::createClient();
        $me = UserFactory::createOne();
        $utc = new \DateTimeZone('UTC');
        for ($i = 0; $i < 95; $i++) {
            PhotoFactory::new()
                ->completed()
                ->ownedBy($me)
                ->create(['takenAt' => new \DateTimeImmutable('2025-03-19 10:00', $utc)->modify(sprintf(
                    '-%d hours',
                    $i,
                ))]);
        }
        VideoFactory::createOne([
            'owner' => $me,
            'title' => 'Ältestes Video',
            'takenAt' => new \DateTimeImmutable('2024-12-24 18:00', $utc),
            'duration' => 125.0,
        ]);

        $client->loginUser($me);
        $crawler = $client->request('GET', '/timeline');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.timeline-month', 'März 2025');
        self::assertCount(90, $crawler->filter('.timeline-tile'), 'erste Seite');
        self::assertSelectorTextContains('nav[aria-label="Monate"]', '2024');

        $partialUrl = (string) $crawler->filter('[data-infinite-scroll-target="link"]')->attr('data-partial-url');
        $more = $client->request('GET', $partialUrl);
        self::assertCount(6, $more->filter('.timeline-tile'));
        self::assertStringNotContainsString(
            'März 2025',
            $more->filter('.timeline-month')->text(''),
            'Monat steht schon da',
        );
        self::assertSelectorTextContains('.timeline-month', 'Dezember 2024');
        self::assertSelectorTextContains('.timeline-badge', '2:05');
        self::assertSelectorTextContains('.timeline-more', 'Das waren alle Medien.');
    }
}
