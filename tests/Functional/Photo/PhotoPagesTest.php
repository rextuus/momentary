<?php

declare(strict_types=1);

namespace App\Tests\Functional\Photo;

use App\Entity\Photo;
use App\Service\Photo\PhotoAnalyzer;
use App\Service\Photo\PhotoSearchIndex;
use App\Service\Storage\StoragePathProvider;
use App\Tests\Factory\PhotoFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFaceFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PhotoPagesTest extends WebTestCase
{
    private KernelBrowser $client;

    /** @var list<int> */
    private array $analyzed = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        // Analyse (Python, AWS, Gemini) ist eigens getestet; hier zählt nur, dass sie angestoßen wird
        $analyzer = $this->createStub(PhotoAnalyzer::class);
        $analyzer
            ->method('analyze')
            ->willReturnCallback(function (Photo $photo): void {
                $this->analyzed[] = (int) $photo->getId();
            });
        static::getContainer()->set(PhotoAnalyzer::class, $analyzer);
        static::getContainer()->set(PhotoSearchIndex::class, $this->createStub(PhotoSearchIndex::class));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove(
            static::getContainer()->get(StoragePathProvider::class)->getAbsoluteFilePath('photos'),
        );
        parent::tearDown();
    }

    public function testOldUploadAddressLeadsToTheCommonUpload(): void
    {
        $this->client->loginUser(UserFactory::createOne());
        $this->client->request('GET', '/photo/upload');

        self::assertResponseRedirects('/upload');
    }

    public function testShowPageMarksFacesAndLinksPersons(): void
    {
        $owner = UserFactory::createOne();
        $photo = PhotoFactory::new()->completed()->ownedBy($owner)->create(['title' => 'Am Strand']);
        VideoFaceFactory::createOne([
            'video' => null,
            'medium' => $photo,
            'timestamp' => 0,
            'faceImage' => $photo->getDisplayFile(),
            'boundingBox' => ['Left' => 0.25, 'Top' => 0.1, 'Width' => 0.2, 'Height' => 0.3],
        ]);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/photo/' . $photo->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Am Strand');
        self::assertStringContainsString(
            'left: 25%; top: 10%; width: 20%; height: 30%;',
            (string) $crawler->filter('.face-box')->attr('style'),
        );
        self::assertSelectorExists('form[action$="/delete"]');
    }

    public function testStrangersCannotSeePrivatePhotosButEveryoneSeesPublicOnes(): void
    {
        $owner = UserFactory::createOne();
        $private = PhotoFactory::new()->completed()->ownedBy($owner)->create(['title' => 'Privat']);
        $public = PhotoFactory::new()->completed()->public()->ownedBy($owner)->create(['title' => 'Öffentlich']);

        $this->client->loginUser(UserFactory::createOne());
        $this->client->request('GET', '/photo/' . $private->getId());
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/photo/' . $public->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action$="/delete"]', 'Fremde dürfen nicht löschen');

        $crawler = $this->client->request('GET', '/photo/');
        self::assertCount(1, $crawler->filter('.photo-tile'), 'Liste zeigt nur sichtbare Fotos');
    }

    public function testOwnerDeletesPhotoWithFiles(): void
    {
        $owner = UserFactory::createOne();
        $photo = PhotoFactory::new()->completed()->ownedBy($owner)->create();
        $dir = static::getContainer()
            ->get(StoragePathProvider::class)
            ->getAbsoluteFilePath('photos/' . $photo->getId());
        new Filesystem()->dumpFile($dir . '/original.jpg', 'x');
        $photoId = $photo->getId();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/photo/' . $photoId);
        $this->client->submitForm('Foto löschen');

        self::assertResponseRedirects('/photo/');
        self::assertNull(static::getContainer()->get(EntityManagerInterface::class)->find(Photo::class, $photoId));
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testReanalyzeQueuesPhotoAgain(): void
    {
        $owner = UserFactory::createOne();
        $photo = PhotoFactory::new()->completed()->ownedBy($owner)->create();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/photo/' . $photo->getId());
        $this->client->submitForm('Neu analysieren');

        self::assertSame([$photo->getId()], $this->analyzed);
    }
}
