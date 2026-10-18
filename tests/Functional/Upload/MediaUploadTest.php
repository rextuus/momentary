<?php

declare(strict_types=1);

namespace App\Tests\Functional\Upload;

use App\Entity\Medium;
use App\Entity\Photo;
use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Service\Photo\PhotoAnalyzer;
use App\Service\Photo\PhotoSearchIndex;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Queue\ImportQueueManager;
use App\Service\VideoSearchIndex;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Ein Upload für Videos und Fotos.
 */
final class MediaUploadTest extends WebTestCase
{
    private KernelBrowser $client;

    /** @var list<int> */
    private array $analyzed = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        // Analyse und Video-Pipeline sind eigens getestet
        $analyzer = $this->createStub(PhotoAnalyzer::class);
        $analyzer
            ->method('analyze')
            ->willReturnCallback(function (Photo $photo): void {
                $this->analyzed[] = (int) $photo->getId();
            });
        $container = static::getContainer();
        $container->set(PhotoAnalyzer::class, $analyzer);
        $container->set(ImportQueueManager::class, $this->createStub(ImportQueueManager::class));
        $container->set(PhotoSearchIndex::class, $this->createStub(PhotoSearchIndex::class));
        $container->set(VideoSearchIndex::class, $this->createStub(VideoSearchIndex::class));
    }

    protected function tearDown(): void
    {
        $paths = static::getContainer()->get(StoragePathProvider::class);
        foreach (static::getContainer()
            ->get(EntityManagerInterface::class)
            ->getRepository(Medium::class)
            ->findAll() as $medium) {
            $dir = $medium instanceof Video ? 'videos/' : 'photos/';
            new Filesystem()->remove($paths->getAbsoluteFilePath($dir . $medium->getId()));
        }
        parent::tearDown();
    }

    public function testVideosAndPhotosGoThroughTheSameUpload(): void
    {
        $me = UserFactory::createOne();
        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/upload');
        self::assertResponseIsSuccessful();
        $root = $crawler->filter('#uploader');
        $csrf = (string) $root->attr('data-csrf');
        self::assertStringContainsString('heic', (string) $root->attr('data-extensions'));

        $photo = $this->send($csrf, 'IMG_0001.JPG', 'foto', 'Mallorca');
        self::assertSame(['ok', 'photo'], [$photo['status'], $photo['type']]);
        self::assertSame('Mallorca', $photo['tag']);

        $video = $this->send($csrf, '20250319_121537.MOV', 'video', 'Mallorca');
        self::assertSame(['ok', 'video'], [$video['status'], $video['type']]);

        $again = $this->send($csrf, 'Kopie.jpg', 'foto', '');
        self::assertSame('duplicate', $again['status']);
        self::assertSame($photo['url'], $again['url']);

        $text = $this->send($csrf, 'notes.txt', 'text', '');
        self::assertSame('error', $text['status']);
        self::assertResponseStatusCodeSame(415);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $photos = $em->getRepository(Photo::class)->findAll();
        $videos = $em->getRepository(Video::class)->findAll();
        self::assertCount(1, $photos);
        self::assertCount(1, $videos);
        self::assertSame([$photos[0]->getId()], $this->analyzed);
        foreach ([$photos[0], $videos[0]] as $medium) {
            self::assertSame($me->getId(), $medium->getOwner()?->getId(), 'auch Videos gehören dem, der sie hochlädt');
            self::assertSame(['Mallorca'], array_map(static fn($t) => $t->getName(), $medium->getTags()->toArray()));
        }
        self::assertSame('20250319_121537', $videos[0]->getTitle());
        self::assertSame(VideoStatus::QUEUED, $videos[0]->getStatus());
        $source = $videos[0]->getSourceFile();
        self::assertNotNull($source);
        self::assertFileExists(static::getContainer()->get(StoragePathProvider::class)->getAbsolutePath($source));
    }

    public function testCheckFindsExistingFilesOfBothTypes(): void
    {
        $this->client->loginUser(UserFactory::createOne());
        $csrf = (string) $this->client->request('GET', '/upload')->filter('#uploader')->attr('data-csrf');
        $this->send($csrf, 'clip.mp4', 'video', '');

        $this->client->request(
            'POST',
            '/upload/check',
            ['hash' => hash('sha256', 'video')],
            [],
            ['HTTP_X-CSRF-Token' => $csrf],
        );
        $result = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($result['exists']);
        self::assertStringStartsWith('/video/', $result['url']);

        $this->client->request(
            'POST',
            '/upload/check',
            ['hash' => hash('sha256', 'neu')],
            [],
            ['HTTP_X-CSRF-Token' => $csrf],
        );
        self::assertFalse(json_decode((string) $this->client->getResponse()->getContent(), true)['exists']);
    }

    /**
     * @return array<string, mixed>
     */
    private function send(string $csrf, string $name, string $content, string $batch): array
    {
        $path = sys_get_temp_dir() . '/' . bin2hex(random_bytes(6)) . '-' . $name;
        file_put_contents($path, $content);
        $this->client->request(
            'POST',
            '/upload/file',
            ['batch_name' => $batch],
            ['file' => new UploadedFile($path, $name, null, null, true)],
            ['HTTP_X-CSRF-Token' => $csrf],
        );

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
