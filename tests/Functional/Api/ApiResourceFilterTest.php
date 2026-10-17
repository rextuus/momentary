<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\VideoScene;
use App\Enum\VideoStatus;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoFactory;
use App\Tests\Factory\VideoSceneFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiResourceFilterTest extends WebTestCase
{
    use OAuth2ClientTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testTagsArePublicAndFilterableByPartialName(): void
    {
        TagFactory::createOne(['name' => 'Meer']);
        TagFactory::createOne(['name' => 'Meerjungfrau']);
        TagFactory::createOne(['name' => 'Berg']);

        self::assertSame(['Meer', 'Meerjungfrau'], $this->names($this->collection('/api/tags?name=meer')));
        self::assertCount(3, $this->collection('/api/tags'));
    }

    public function testVideoCollectionRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/videos');

        self::assertResponseStatusCodeSame(401);
    }

    public function testVideoCollectionOnlyContainsCompletedVideos(): void
    {
        VideoFactory::new()->completed()->create(['title' => 'Fertig']);
        VideoFactory::createOne(['title' => 'In Arbeit', 'status' => VideoStatus::SCENE_DETECTION]);

        $this->authenticateApiClient($this->client, UserFactory::createOne());

        self::assertSame(['Fertig'], $this->titles($this->collection('/api/videos')));
    }

    public function testVideosCanBeFilteredByPartialTitle(): void
    {
        VideoFactory::new()->completed()->create(['title' => 'Urlaub Italien']);
        VideoFactory::new()->completed()->create(['title' => 'Geburtstag']);

        $this->authenticateApiClient($this->client, UserFactory::createOne());

        self::assertSame(['Urlaub Italien'], $this->titles($this->collection('/api/videos?title=urlaub')));
    }

    public function testVideosCanBeFilteredByPersonName(): void
    {
        $withAnna = VideoFactory::new()->completed()->create(['title' => 'Mit Anna']);
        $withBen = VideoFactory::new()->completed()->create(['title' => 'Mit Ben']);
        VideoFaceFactory::createOne(['video' => $withAnna, 'person' => PersonFactory::new(['name' => 'Anna'])]);
        VideoFaceFactory::createOne(['video' => $withBen, 'person' => PersonFactory::new(['name' => 'Ben'])]);

        $this->authenticateApiClient($this->client, UserFactory::createOne());

        self::assertSame(['Mit Anna'], $this->titles($this->collection('/api/videos?videoFaces.person.name=ann')));
    }

    public function testVideosCanBeFilteredBySceneTag(): void
    {
        $beach = TagFactory::createOne(['name' => 'Strand']);
        $mountain = TagFactory::createOne(['name' => 'Berg']);

        $atBeach = VideoFactory::new()->completed()->create(['title' => 'Am Strand']);
        $inMountains = VideoFactory::new()->completed()->create(['title' => 'In den Bergen']);
        VideoSceneFactory::new()->afterInstantiate(static function (VideoScene $scene) use ($beach): void {
            $scene->addSceneTag($beach);
        })->create(['video' => $atBeach]);
        VideoSceneFactory::new()->afterInstantiate(static function (VideoScene $scene) use ($mountain): void {
            $scene->addSceneTag($mountain);
        })->create(['video' => $inMountains]);

        $this->authenticateApiClient($this->client, UserFactory::createOne());

        self::assertSame(['Am Strand'], $this->titles($this->collection('/api/videos?scenes.tags.name=stra')));
        self::assertSame(
            ['In den Bergen'],
            $this->titles($this->collection('/api/videos?scenes.tags.id=' . $mountain->getId())),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collection(string $url): array
    {
        $this->client->request('GET', $url, server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $data['member'] ?? $data['hydra:member'];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<string>
     */
    private function names(array $items): array
    {
        $names = array_column($items, 'name');
        sort($names);

        return $names;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<string>
     */
    private function titles(array $items): array
    {
        $titles = array_column($items, 'title');
        sort($titles);

        return $titles;
    }
}
