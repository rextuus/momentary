<?php

declare(strict_types=1);

namespace App\Tests\Functional\Search;

use App\Tests\Factory\PhotoFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFactory;
use Meilisearch\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Meilisearch ist ersetzt: geprüft wird, was aus den Treffern auf der Seite landet.
 */
final class MediaSearchTest extends WebTestCase
{
    public function testShowsOnlyVisibleHitsWithMomentsAndFacetsFromVisibleHitsOnly(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $me = UserFactory::createOne();
        $stranger = UserFactory::createOne();
        $myVideo = VideoFactory::new()->completed()->create(['owner' => $me, 'title' => 'Feierabend']);
        $hiddenVideo = VideoFactory::new()->completed()->create(['owner' => $stranger, 'title' => 'Geheim']);
        $publicPhoto = PhotoFactory::new()->completed()->public()->create(['title' => 'Butterbrot']);
        $hiddenPhoto = PhotoFactory::new()->completed()->create(['owner' => $stranger, 'title' => 'Privat']);

        $meili = $this->createMock(Client::class);
        $meili
            ->expects(self::once())
            ->method('multiSearch')
            ->willReturnCallback(static function (array $queries) use (
                $myVideo,
                $hiddenVideo,
                $publicPhoto,
                $hiddenPhoto,
            ): array {
                $filters = array_map(static fn($q) => $q->toArray()['filter'] ?? null, $queries);
                self::assertSame(
                    [
                        ['persons = "Joscha"'],
                        ['persons = "Joscha"'],
                    ],
                    $filters,
                    'Filter gilt für beide Indizes',
                );

                return [
                    'results' => [
                        [
                            'indexUid' => 'videos',
                            'hits' => [
                                [
                                    'id' => (string) $hiddenVideo->getId(),
                                    'persons' => ['Joscha', 'Fremde Person'],
                                    'tags' => [],
                                ],
                                [
                                    'id' => (string) $myVideo->getId(),
                                    'persons' => ['Joscha'],
                                    'tags' => ['Essen'],
                                    'transcript' => [[
                                        'start' => 29,
                                        'end' => 31,
                                        'text' => 'Immerhin Butter, immerhin irgendwie.',
                                    ]],
                                ],
                            ],
                        ],
                        [
                            'indexUid' => 'photos',
                            'hits' => [
                                [
                                    'id' => (string) $hiddenPhoto->getId(),
                                    'persons' => ['Fremde Person'],
                                    'tags' => ['Geheimtag'],
                                ],
                                ['id' => (string) $publicPhoto->getId(), 'persons' => ['Joscha'], 'tags' => ['Essen']],
                            ],
                        ],
                    ],
                ];
            });
        static::getContainer()->set(Client::class, $meili);

        $client->loginUser($me);
        $crawler = $client->request('GET', '/search?q=Butter&persons[]=Joscha');

        self::assertResponseIsSuccessful();
        $page = $crawler->filter('main, body')->text();
        self::assertStringContainsString('Feierabend', $page);
        self::assertStringContainsString('Butterbrot', $page);
        self::assertStringNotContainsString('Geheim', $page, 'fremde private Treffer fehlen, auch als Tag');
        self::assertStringNotContainsString('Fremde Person', $page, 'Facetten verraten keine fremden Personen');
        self::assertStringNotContainsString('Privat', $page);

        $moment = $crawler->filter('.search-hit .moment')->first();
        self::assertStringEndsWith('?t=29', (string) $moment->attr('href'));
        self::assertSame('Butter', $moment->filter('mark')->text());
        self::assertSelectorTextContains('aside', 'Essen 2');
    }

    public function testUnavailableSearchShowsHint(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $meili = $this->createStub(Client::class);
        $meili->method('multiSearch')->willThrowException(new \RuntimeException('connection refused'));
        static::getContainer()->set(Client::class, $meili);

        $client->loginUser(UserFactory::createOne());
        $client->request('GET', '/search?q=Pool');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'nicht erreichbar');
    }
}
