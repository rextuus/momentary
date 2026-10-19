<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Video;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\UserGroupFactory;
use App\Tests\Factory\VideoFactory;
use App\Tests\Factory\VideoSceneFactory;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Search\SearchResult;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SearchApiTest extends WebTestCase
{
    use OAuth2ClientTrait;

    private KernelBrowser $client;
    private Indexes&Stub $index;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->index = $this->createStub(Indexes::class);
        $meilisearch = $this->createStub(Client::class);
        $meilisearch->method('index')->willReturnCallback(fn(): Indexes => $this->index);
        self::getContainer()->set(Client::class, $meilisearch);
    }

    public function testOwnerSeesOwnPrivateVideoUnblurred(): void
    {
        $owner = UserFactory::createOne();
        $video = $this->privateVideoWithThumbnail($owner);
        $this->meilisearchReturns([['id' => $video->getId()]]);

        $this->authenticateApiClient($this->client, $owner);
        $result = $this->search('?q=Urlaub');

        self::assertCount(1, $result);
        self::assertSame(0, $result[0]['blur']);
        self::assertSame('Sichtbar (Eigentümer)', $result[0]['accessInfo']);
        self::assertStringNotContainsString('/bl:', $result[0]['thumbnailUrl']);
    }

    public function testGroupMemberSeesSharedVideoUnblurred(): void
    {
        $member = UserFactory::createOne();
        $group = UserGroupFactory::new()->withMembers([$member])->create(['name' => 'Familie']);
        $video = $this->privateVideoWithThumbnail(UserFactory::createOne(), [$group]);
        $this->meilisearchReturns([['id' => $video->getId()]]);

        $this->authenticateApiClient($this->client, $member);
        $result = $this->search();

        self::assertSame(0, $result[0]['blur']);
        self::assertSame('Sichtbar (Mitglied in Gruppe Familie)', $result[0]['accessInfo']);
    }

    public function testStrangersAndAnonymousDoNotFindPrivateVideos(): void
    {
        $video = $this->privateVideoWithThumbnail(UserFactory::createOne());
        $this->meilisearchReturns([['id' => $video->getId()]]);

        self::assertSame([], $this->search(), 'ohne Anmeldung');
        $this->authenticateApiClient($this->client, UserFactory::createOne());
        self::assertSame([], $this->search(), 'fremdes privates Video');
    }

    public function testPublicSceneThumbnailIsUnblurredInsidePrivateVideo(): void
    {
        $video = $this->privateVideoWithThumbnail(UserFactory::createOne());
        VideoSceneFactory::createOne([
            'video' => $video,
            'startSeconds' => 10.0,
            'endSeconds' => 20.0,
            'isPublic' => true,
        ]);
        VideoSceneFactory::createOne([
            'video' => $video,
            'sceneNumber' => 2,
            'startSeconds' => 20.0,
            'endSeconds' => 30.0,
            'isPublic' => false,
        ]);

        $this->meilisearchReturns([[
            'id' => $video->getId(),
            'persons_with_scenes' => [
                ['name' => 'Anna', 'start' => 10, 'thumbnailUrl' => 'images/anna.jpg'],
                ['name' => 'Anna', 'start' => 20, 'thumbnailUrl' => 'images/anna2.jpg'],
            ],
        ]]);

        $this->authenticateApiClient($this->client, UserFactory::createOne());
        $persons = $this->search()[0]['persons_with_scenes'];

        self::assertCount(1, $persons, 'nur die Fundstelle in der öffentlichen Szene');
        self::assertStringNotContainsString('/bl:', $persons[0]['thumbnailUrl']);
        self::assertSame(20, $persons[0]['endSeconds']);
    }

    public function testResultsKeepMeilisearchOrderAndSkipStaleIndexEntries(): void
    {
        $first = VideoFactory::new()->public()->create(['title' => 'Erstes']);
        $second = VideoFactory::new()->public()->create(['title' => 'Zweites']);
        $this->meilisearchReturns([['id' => $second->getId()], ['id' => 999999], ['id' => $first->getId()]]);

        $result = $this->search();

        self::assertSame([$second->getId(), $first->getId()], array_column($result, 'id'));
    }

    public function testPersonAndTagFiltersAreCombinedAndEscaped(): void
    {
        $index = $this->createMock(Indexes::class);
        $this->index = $index;
        $index
            ->expects(self::once())
            ->method('search')
            ->with('Strand', [
                'limit' => 20,
                'filter' => 'persons = "Anna \"Ani\" B." AND persons = "Ben" AND tags = "Meer"',
            ])
            ->willReturn(self::searchResult([]));

        $this->client->request('GET', '/api/search', [
            'q' => ' Strand ',
            'persons' => ['Anna "Ani" B.', 'Ben'],
            'tags' => ['Meer'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('[]', $this->client->getResponse()->getContent());
    }

    public function testEmptyQuerySearchesWithoutTerm(): void
    {
        $index = $this->createMock(Indexes::class);
        $this->index = $index;
        $index
            ->expects(self::once())
            ->method('search')
            ->with(null, ['limit' => 20])
            ->willReturn(self::searchResult([]));

        $this->client->request('GET', '/api/search');

        self::assertResponseIsSuccessful();
    }

    public function testUnreachableSearchBackendReturnsBadGateway(): void
    {
        $this->index->method('search')->willThrowException(new \RuntimeException('connection refused'));

        $this->client->request('GET', '/api/search?q=x');

        self::assertResponseStatusCodeSame(502);
        self::assertStringContainsString('Suche nicht erreichbar', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @param list<\App\Entity\UserGroup> $groups
     */
    private function privateVideoWithThumbnail(\App\Entity\User $owner, array $groups = []): Video
    {
        return VideoFactory::new()
            ->private()
            ->ownedBy($owner)
            ->sharedWith($groups)
            ->create(['title' => 'Urlaub', 'thumbnailFile' => FileFactory::new()->videoThumbnail()]);
    }

    /**
     * @param list<array<string, mixed>> $hits
     */
    private function meilisearchReturns(array $hits): void
    {
        $this->index->method('search')->willReturn(self::searchResult($hits));
    }

    /**
     * @param list<array<string, mixed>> $hits
     */
    private static function searchResult(array $hits): SearchResult
    {
        return new SearchResult([
            'hits' => $hits,
            'estimatedTotalHits' => \count($hits),
            'processingTimeMs' => 1,
            'query' => '',
            'limit' => 20,
            'offset' => 0,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function search(string $query = ''): array
    {
        $this->client->request('GET', '/api/search' . $query);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
