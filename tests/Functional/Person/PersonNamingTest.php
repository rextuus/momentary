<?php

declare(strict_types=1);

namespace App\Tests\Functional\Person;

use App\Entity\Person;
use App\Entity\PersonPairDismissal;
use App\Enum\PersonStatus;
use App\Service\Photo\PhotoSearchIndex;
use App\Service\VideoSearchIndex;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\PhotoFactory;
use App\Tests\Factory\TagCategoryFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoSceneFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PersonNamingTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set(VideoSearchIndex::class, $this->createStub(VideoSearchIndex::class));
        static::getContainer()->set(PhotoSearchIndex::class, $this->createStub(PhotoSearchIndex::class));
    }

    public function testDuplicatesCanBeMergedOrDismissed(): void
    {
        $oma = PersonFactory::createOne(['name' => 'Oma', 'status' => PersonStatus::IDENTIFIED]);
        $same = PersonFactory::createOne(['name' => 'unknown_1', 'status' => PersonStatus::NEW]);
        $other = PersonFactory::createOne(['name' => 'unknown_2', 'status' => PersonStatus::NEW]);
        VideoFaceFactory::createOne(['person' => $oma, 'faceLabel' => 'oma', 'faceImage' => FileFactory::new()]);
        VideoFaceFactory::createOne([
            'person' => $same,
            'faceLabel' => 'same',
            'faceImage' => FileFactory::new(),
            'matchCandidates' => ['oma' => 93.0],
        ]);
        VideoFaceFactory::createOne([
            'person' => $other,
            'faceLabel' => 'other',
            'faceImage' => FileFactory::new(),
            'matchCandidates' => ['oma' => 75.0],
        ]);
        $this->client->loginUser(UserFactory::new()->admin()->create());

        $crawler = $this->client->request('GET', '/admin/person-duplicates');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card', 'sehr wahrscheinlich');
        self::assertCount(2, $crawler->filter('button[value=same]'));

        $this->client->submit($crawler->filter('button[value=same]')->first()->form());
        self::assertResponseRedirects('/admin/person-duplicates');
        $crawler = $this->client->followRedirect();
        self::assertSame(PersonStatus::MERGED, $this->person($same)->getStatus());
        self::assertCount(1, $crawler->filter('button[value=different]'));

        $this->client->submit($crawler->filter('button[value=different]')->form());
        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('button[value=same]'));
        self::assertSame(1, $this->em()->getRepository(PersonPairDismissal::class)->count([]));
        self::assertSame(PersonStatus::NEW, $this->person($other)->getStatus());
    }

    public function testUnnamedPersonsOfAnEventCanBeNamedFromTheEventPage(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $oma = PersonFactory::createOne(['name' => 'Oma', 'status' => PersonStatus::IDENTIFIED]);
        $unknown = PersonFactory::createOne(['name' => 'unknown_3', 'status' => PersonStatus::NEW]);
        $stranger = PersonFactory::createOne(['name' => 'unknown_4', 'status' => PersonStatus::NEW]);
        $hinted = PersonFactory::createOne(['name' => 'unknown_5', 'status' => PersonStatus::NEW]);
        $photo = PhotoFactory::new()->completed()->create();
        foreach ([$unknown, $stranger] as $person) {
            VideoFaceFactory::createOne([
                'medium' => $photo,
                'person' => $person,
                'faceImage' => $photo->getDisplayFile(),
            ]);
        }
        // Video-Szene, in der „Joscha“ fällt
        $scene = VideoSceneFactory::createOne();
        $scene->addSceneTag(TagFactory::createOne([
            'name' => 'Joscha',
            'category' => TagCategoryFactory::createOne(['name' => 'Erwähnte Personen']),
        ]));
        VideoFaceFactory::createOne([
            'video' => $scene->getVideo(),
            'videoScene' => $scene,
            'person' => $hinted,
            'faceImage' => FileFactory::new(),
        ]);
        $event = EventFactory::createOne(['owner' => $admin]);
        $event->addMedium($photo);
        $video = $scene->getVideo();
        self::assertNotNull($video);
        $event->addMedium($video);
        $this->em()->flush();
        $this->em()->clear();
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/event/' . $event->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#people', 'Wer ist dabei?');
        self::assertSelectorExists('#known-names option[value=Oma]');
        self::assertSelectorTextContains('#people', 'Fällt im Gespräch');

        // Vorhandener Name: zusammenführen
        $form = $crawler
            ->filter(sprintf('form[action$="/person/%d"]', $unknown->getId()))
            ->selectButton('Benennen')
            ->form(['name' => 'oma']);
        $this->client->submit($form);
        self::assertResponseRedirects('/event/' . $event->getId() . '#people');
        self::assertSame($oma->getId(), $this->person($unknown)->getMergedInto()?->getId());

        // Hinweis aus dem Transkript übernehmen
        $crawler = $this->client->request('GET', '/event/' . $event->getId());
        $this->client->submit($crawler->filter('button[name=hint][value=Joscha]')->form());
        self::assertSame('Joscha', $this->person($hinted)->getName());
        self::assertSame(PersonStatus::IDENTIFIED, $this->person($hinted)->getStatus());

        // Fremde ausblenden
        $crawler = $this->client->request('GET', '/event/' . $event->getId());
        $this->client->submit(
            $crawler
                ->filter(sprintf('form[action$="/person/%d"]', $stranger->getId()))
                ->selectButton('Unbekannt')
                ->form(),
        );
        self::assertSame(PersonStatus::UNKNOWN, $this->person($stranger)->getStatus());
        $this->client->request('GET', '/event/' . $event->getId());
        self::assertSelectorNotExists('#people');
    }

    public function testOnlyAdminsCanNamePersons(): void
    {
        $owner = UserFactory::createOne();
        $person = PersonFactory::createOne(['name' => 'unknown_6', 'status' => PersonStatus::NEW]);
        $photo = PhotoFactory::new()->completed()->ownedBy($owner)->create();
        VideoFaceFactory::createOne(['medium' => $photo, 'person' => $person, 'faceImage' => $photo->getDisplayFile()]);
        $event = EventFactory::createOne(['owner' => $owner]);
        $event->addMedium($photo);
        $this->em()->flush();
        $this->em()->clear();
        $this->client->loginUser($owner);

        $this->client->request('GET', '/event/' . $event->getId());
        self::assertSelectorNotExists('#people');
        $this->client->request('POST', sprintf('/event/%d/person/%d', $event->getId(), $person->getId()), [
            'name' => 'X',
        ]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/person-duplicates');
        self::assertResponseStatusCodeSame(403);
    }

    private function person(Person $person): Person
    {
        $this->em()->clear();
        $reloaded = $this->em()->find(Person::class, $person->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
