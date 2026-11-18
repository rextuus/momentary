<?php

declare(strict_types=1);

namespace App\Tests\Functional\Video;

use App\Entity\Person;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Enum\PersonStatus;
use App\Service\Aws\AmazonRekognitionService;
use App\Service\Storage\StoragePathProvider;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Ein gelöschtes Video hinterlässt nichts: keine Gesichter, keine Gesichtsbilder, keine Frames, keine leeren
 * automatischen Personen. AWS wird nur für Rekognition-Gesichter gefragt, und ein AWS-Fehler blockiert nichts.
 */
final class VideoDeleteTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $storageRoot;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Der AWS-Mock muss über den Request hinweg erhalten bleiben
        $this->client->disableReboot();
        $this->storageRoot = static::getContainer()->get(StoragePathProvider::class)->getStorageRoot();
    }

    public function testDeletesFacesImagesFramesAndOrphanedPersons(): void
    {
        $rekognition = $this->createMock(AmazonRekognitionService::class);
        $rekognition->expects(self::once())->method('deleteFaces')->with(['aws-1']);
        static::getContainer()->set(AmazonRekognitionService::class, $rekognition);

        [$video, $auto, $anna] = $this->videoWithFaces();
        $videoId = (int) $video->getId();

        $this->deleteViaOverview($video);

        self::assertResponseRedirects('/video/');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(Video::class, $videoId));
        self::assertSame(0, $em->getRepository(VideoFace::class)->count([]));
        self::assertNull($em->find(Person::class, $auto), 'leere automatische Person entfernt');
        self::assertNotNull($em->find(Person::class, $anna), 'benannte Person bleibt');
        self::assertDirectoryDoesNotExist($this->storageRoot . '/images/faces/' . $videoId, 'Gesichtsbilder weg');
        self::assertDirectoryDoesNotExist($this->storageRoot . '/frames/' . $videoId);
    }

    public function testExpiredAwsAccessDoesNotBlockDeletion(): void
    {
        $rekognition = $this->createStub(AmazonRekognitionService::class);
        $rekognition->method('deleteFaces')->willThrowException(new \RuntimeException('The security token is expired'));
        static::getContainer()->set(AmazonRekognitionService::class, $rekognition);

        [$video] = $this->videoWithFaces();
        $videoId = (int) $video->getId();

        $this->deleteViaOverview($video);

        self::assertResponseRedirects('/video/');
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull(static::getContainer()->get(EntityManagerInterface::class)->find(Video::class, $videoId));
    }

    /**
     * Ein Rekognition- und ein InsightFace-Gesicht einer automatischen Person, dazu Anna; Gesichtsbild und Frame auf
     * der Platte.
     *
     * @return array{Video, int, int} Video, ID der automatischen Person, ID von Anna
     */
    private function videoWithFaces(): array
    {
        $admin = UserFactory::new()->admin()->create();
        $video = VideoFactory::new()->completed()->create(['owner' => $admin]);
        $auto = PersonFactory::createOne(['name' => 'unknown_1234', 'status' => PersonStatus::NEW]);
        $anna = PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        VideoFaceFactory::createOne(['video' => $video, 'person' => $auto, 'faceLabel' => 'aws-1']);
        VideoFaceFactory::createOne([
            'video' => $video,
            'person' => $auto,
            'faceLabel' => 'insight-1',
            'provider' => 'insightface',
        ]);
        VideoFaceFactory::createOne(['video' => $video, 'person' => $anna, 'provider' => 'insightface']);

        $fs = new Filesystem();
        $fs->dumpFile(sprintf('%s/images/faces/%d/frame.jpg', $this->storageRoot, (int) $video->getId()), 'jpeg');
        $fs->dumpFile(
            sprintf('%s/frames/%d/analysis/frame_0001.jpg', $this->storageRoot, (int) $video->getId()),
            'jpeg',
        );
        $this->client->loginUser($admin);

        return [$video, (int) $auto->getId(), (int) $anna->getId()];
    }

    private function deleteViaOverview(Video $video): void
    {
        $crawler = $this->client->request('GET', '/video/');
        $form = $crawler->filter(sprintf('form[action$="/video/%d/delete"]', (int) $video->getId()))->form();
        $this->client->submit($form);
    }
}
