<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\PoolRule;
use App\Entity\VideoScene;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\TagCategoryFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\UserGroupFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoFactory;
use App\Tests\Factory\VideoSceneFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die API zeigt einem Pool-Mitglied nur die Medien seines Pools – und nur Personen und Tags daraus.
 */
final class ApiVisibilityTest extends WebTestCase
{
    use OAuth2ClientTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testPoolMemberSeesOnlyPoolVideosPersonsAndTags(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $mother = UserFactory::createOne();
        $home = VideoFactory::new()->completed()->create(['title' => 'Weihnachten 1998', 'owner' => $admin]);
        $trip = VideoFactory::new()->completed()->create(['title' => 'Mallorca', 'owner' => $admin]);
        $oma = PersonFactory::createOne(['name' => 'Oma', 'status' => \App\Enum\PersonStatus::IDENTIFIED]);
        $friend = PersonFactory::createOne(['name' => 'Kumpel', 'status' => \App\Enum\PersonStatus::IDENTIFIED]);
        $homeFace = VideoFaceFactory::createOne(['video' => $home, 'person' => $oma]);
        $tripFace = VideoFaceFactory::createOne(['video' => $trip, 'person' => $friend]);
        $mentioned = TagCategoryFactory::createOne(['name' => 'Erwähnte Personen']);
        foreach ([[$home, 'Opa'], [$trip, 'Joscha']] as [$video, $name]) {
            $tag = TagFactory::createOne(['name' => $name, 'category' => $mentioned]);
            VideoSceneFactory::new()->afterInstantiate(static function (VideoScene $scene) use ($tag): void {
                $scene->addSceneTag($tag);
            })->create(['video' => $video]);
        }
        $christmas = EventFactory::createOne(['owner' => $admin]);
        $christmas->addMedium($home);
        $pool = UserGroupFactory::new()->withMembers([$mother])->create(['owner' => $admin]);
        $pool->addRule(new PoolRule($pool)->setEvent($christmas));
        static::getContainer()->get('doctrine')->getManager()->flush();

        $this->authenticateApiClient($this->client, $mother);

        self::assertSame(['Weihnachten 1998'], array_column($this->collection('/api/videos'), 'title'));
        self::assertSame(['Oma'], array_column($this->collection('/api/persons'), 'name'));
        self::assertSame(
            ['Opa'],
            array_column($this->collection('/api/tags?name=o'), 'name'),
            'Joscha fällt nur im fremden Video',
        );
        self::assertSame([$homeFace->getId()], array_column($this->collection('/api/video_faces'), 'id'));

        foreach ([
            '/api/videos/' . $trip->getId(),
            '/api/video_faces/' . $tripFace->getId(),
            '/api/people/' . $friend->getId(),
        ] as $url) {
            $this->client->request('GET', $url, server: ['HTTP_ACCEPT' => 'application/ld+json']);
            self::assertResponseStatusCodeSame(404, $url);
        }
        $this->client->request('GET', '/api/videos/' . $trip->getId() . '/stream-url');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousSeesOnlyPublicPersons(): void
    {
        $private = VideoFactory::new()->completed()->create();
        VideoFaceFactory::createOne([
            'video' => $private,
            'person' => PersonFactory::new(['name' => 'Geheim', 'status' => \App\Enum\PersonStatus::IDENTIFIED]),
        ]);

        self::assertSame([], $this->collection('/api/persons'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collection(string $url): array
    {
        $this->client->request('GET', $url, server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $data['member'] ?? [];
    }
}
