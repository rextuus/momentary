<?php

declare(strict_types=1);

namespace App\Tests\Integration\Face;

use App\Entity\Video;
use App\Entity\VideoFace;
use App\Repository\VideoFaceRepository;
use App\Service\Face\Embedding\EmbeddingIndex;
use App\Service\Face\Embedding\EmbeddingMath;
use App\Service\Face\FaceRecognitionSettingsProvider;
use App\Service\Face\InsightFace\FaceServiceClient;
use App\Service\Face\Provider\FaceRecognitionProviders;
use App\Service\Face\Provider\InsightFaceProvider;
use App\Service\ImageFileService;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Analyze\FrameAnalyzer;
use App\Service\Video\Processing\Exception\VideoGoneException;
use App\Tests\Factory\VideoFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem as LocalFs;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * FrameAnalyzer mit InsightFace: face-service per MockHttpClient, Abgleich über EmbeddingIndex (SQLite).
 */
final class InsightFaceFrameAnalysisTest extends KernelTestCase
{
    private string $tmpDir;
    private string $frame;

    /** @var list<string> */
    private array $requestedUrls = [];

    /** @var list<array<string, mixed>> */
    private array $responses = [];

    /** Wird vor der Antwort des face-service ausgeführt (z. B. Video löschen). */
    private ?\Closure $duringRequest = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->tmpDir = sys_get_temp_dir() . '/insightface-' . uniqid();
        new LocalFs()->mkdir($this->tmpDir);
        $this->frame = $this->tmpDir . '/frame_0001.jpg';
        file_put_contents($this->frame, 'jpeg');

        $http = new MockHttpClient(function (string $method, string $url): JsonMockResponse {
            $this->requestedUrls[] = $url;
            if ($this->duringRequest !== null) {
                ($this->duringRequest)();
            }

            return new JsonMockResponse(array_shift($this->responses) ?? self::response([]));
        });

        // Zuerst den Anbieter austauschen: FrameAnalyzer und die Einstellungen holen ihn beim ersten Zugriff
        $container->set(FaceRecognitionProviders::class, new FaceRecognitionProviders(new ServiceLocator([
            InsightFaceProvider::NAME => static fn(): InsightFaceProvider => new InsightFaceProvider(
                new FaceServiceClient($http, 'http://face-service.test'),
                new EmbeddingIndex(
                    $container->get(Connection::class),
                    $container->get(FaceRecognitionSettingsProvider::class),
                ),
                $container->get(FaceRecognitionSettingsProvider::class),
            ),
        ]), InsightFaceProvider::NAME));
        $container->set(
            ImageFileService::class,
            new ImageFileService(
                new Filesystem(new LocalFilesystemAdapter($this->tmpDir . '/storage')),
                $container->get(StoragePathProvider::class),
            ),
        );
    }

    protected function tearDown(): void
    {
        new LocalFs()->remove($this->tmpDir);
        parent::tearDown();
    }

    public function testSameFaceInLaterFrameJoinsThePersonAndStoresEmbedding(): void
    {
        $video = VideoFactory::createOne();
        $anna = self::vector(1.0, 0.0);
        $annaLater = EmbeddingMath::normalize([0.95, 0.31, ...array_fill(0, 510, 0.0)]); // Kosinus ~0,95
        $this->responses = [
            self::response([self::face($anna, age: 34, gender: 'F')]),
            self::response([self::face($annaLater, age: 35, gender: 'F')]),
        ];

        $this->analyze($video, 1);
        $this->analyze($video, 2);

        $faces = self::getContainer()->get(VideoFaceRepository::class)->findBy([], ['timestamp' => 'ASC']);
        self::assertCount(2, $faces);
        [$first, $second] = $faces;
        self::assertSame($first->getPerson()?->getId(), $second->getPerson()?->getId(), 'gleiche Person');
        self::assertSame('insightface', $second->getProvider());
        self::assertSame('buffalo_l/w600k_r50', $second->getEmbeddingModel());
        self::assertNull($second->getEmotion(), 'InsightFace kennt keine Emotion');
        self::assertSame('Female', $second->getGender());
        self::assertSame(35, $second->getAge());
        self::assertSame(91.0, $second->getDetectionScore());
        self::assertSame([(string) $first->getFaceLabel()], array_keys($second->getMatchCandidates()));
        self::assertEqualsWithDelta(95.0, $second->getMatchCandidates()[(string) $first->getFaceLabel()], 0.5);
        $embedding = $second->getEmbedding();
        self::assertNotNull($embedding);
        self::assertEqualsWithDelta(1.0, EmbeddingMath::cosine($annaLater, $embedding), 1e-6, 'float32 gespeichert');
        self::assertEqualsWithDelta(
            ['Left' => 0.1, 'Top' => 0.1, 'Width' => 0.2, 'Height' => 0.5],
            $second->getBoundingBox(),
            1e-9,
        );
        self::assertStringContainsString(
            'min_face_px=40&max_faces=10',
            $this->requestedUrls[0],
            'Filter aus den Einstellungen',
        );
    }

    public function testDifferentFaceBecomesNewPerson(): void
    {
        $video = VideoFactory::createOne();
        $this->responses = [
            self::response([self::face(self::vector(1.0, 0.0))]),
            self::response([self::face(self::vector(0.0, 1.0))]),
        ];

        $this->analyze($video, 1);
        $this->analyze($video, 2);

        $faces = self::getContainer()->get(VideoFaceRepository::class)->findAll();
        self::assertCount(2, $faces);
        self::assertNotSame($faces[0]->getPerson()?->getId(), $faces[1]->getPerson()?->getId());
        self::assertSame([], $faces[1]->getMatchCandidates(), 'unter der Kandidatenschwelle');
    }

    public function testVideoDeletedWhileTheServiceWorksLeavesNothingBehind(): void
    {
        $video = VideoFactory::createOne();
        $videoId = (int) $video->getId();
        $this->responses = [self::response([self::face(self::vector(1.0, 0.0))])];
        $this->duringRequest = static function () use ($videoId): void {
            self::getContainer()
                ->get(Connection::class)
                ->executeStatement('DELETE FROM medium WHERE id = ?', [$videoId]);
        };

        try {
            self::getContainer()->get(FrameAnalyzer::class)->analyzeFrame($videoId, $this->frame, 1);
            self::fail('VideoGoneException erwartet');
        } catch (VideoGoneException $e) {
            self::assertStringContainsString((string) $videoId, $e->getMessage());
        }

        self::assertSame(0, self::getContainer()->get(VideoFaceRepository::class)->count([]));
        self::assertDirectoryDoesNotExist($this->tmpDir . '/storage/images/faces/' . $videoId, 'kein Gesichtsbild');
    }

    /**
     * Das enge Fenster zwischen Prüfung und Speichern: MySQL meldet dann einen Fremdschlüssel-Fehler. SQLite prüft in
     * den Tests keine Fremdschlüssel, deshalb löscht ein Listener das Video beim Speichern und wirft den Fehler selbst.
     */
    public function testVideoDeletedWhileSavingRemovesImagePersonsAndFileRow(): void
    {
        $video = VideoFactory::createOne();
        $videoId = (int) $video->getId();
        $this->responses = [self::response([self::face(self::vector(1.0, 0.0))])];

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getEventManager()->addEventListener(Events::onFlush, new class($videoId) {
            public function __construct(
                private readonly int $videoId,
            ) {}

            public function onFlush(OnFlushEventArgs $args): void
            {
                $insertions = $args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions();
                foreach ($insertions as $entity) {
                    if ($entity instanceof VideoFace) {
                        $args
                            ->getObjectManager()
                            ->getConnection()
                            ->executeStatement('DELETE FROM medium WHERE id = ?', [$this->videoId]);
                        throw new ForeignKeyConstraintViolationException(
                            PdoDriverException::new(new \PDOException('FOREIGN KEY constraint failed')),
                            null,
                        );
                    }
                }
            }
        });

        try {
            self::getContainer()->get(FrameAnalyzer::class)->analyzeFrame($videoId, $this->frame, 1);
            self::fail('VideoGoneException erwartet');
        } catch (VideoGoneException $e) {
            self::assertInstanceOf(ForeignKeyConstraintViolationException::class, $e->getPrevious());
        }

        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM video_face'));
        self::assertSame(
            0,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM person'),
            'neu angelegte Person entfernt',
        );
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM files WHERE purpose = 'image_face'"));
        self::assertSame(
            [],
            glob($this->tmpDir . '/storage/images/faces/' . $videoId . '/*') ?: [],
            'Gesichtsbild entfernt',
        );
    }

    public function testDeletedVideoIsGoneBeforeAnyRequest(): void
    {
        $video = VideoFactory::createOne();
        $videoId = (int) $video->getId();
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM medium WHERE id = ?', [$videoId]);
        self::getContainer()->get('doctrine')->getManager()->clear();
        unlink($this->frame); // der Lösch-Flow entfernt auch den Frame-Ordner

        $this->expectException(VideoGoneException::class);

        try {
            self::getContainer()->get(FrameAnalyzer::class)->analyzeFrame($videoId, $this->frame, 1);
        } finally {
            self::assertSame([], $this->requestedUrls, 'face-service nicht gefragt');
        }
    }

    public function testMissingFrameOfExistingVideoIsARealError(): void
    {
        $video = VideoFactory::createOne();
        unlink($this->frame);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Frame file does not exist');

        self::getContainer()
            ->get(FrameAnalyzer::class)
            ->analyzeFrame((int) $video->getId(), $this->frame, 1);
    }

    private function analyze(Video $video, int $timestamp): void
    {
        self::assertTrue(
            self::getContainer()
                ->get(FrameAnalyzer::class)
                ->analyzeFrame((int) $video->getId(), $this->frame, $timestamp),
        );
        self::getContainer()->get('doctrine')->getManager()->clear();
    }

    /**
     * @return list<float> 512 Werte, normalisiert
     */
    private static function vector(float $x, float $y): array
    {
        return EmbeddingMath::normalize([$x, $y, ...array_fill(0, 510, 0.0)]);
    }

    /**
     * @param list<float> $embedding
     *
     * @return array<string, mixed>
     */
    private static function face(array $embedding, int $age = 30, string $gender = 'F'): array
    {
        return [
            'box' => [100, 50, 300, 300],
            'score' => 0.91,
            'landmarks' => [],
            'embedding' => $embedding,
            'age' => $age,
            'gender' => $gender,
        ];
    }

    /**
     * @param list<array<string, mixed>> $faces
     *
     * @return array<string, mixed>
     */
    private static function response(array $faces): array
    {
        return [
            'model' => 'buffalo_l/w600k_r50',
            'width' => 1000,
            'height' => 500,
            'det_size' => 640,
            'faces' => $faces,
            'filtered' => ['too_small' => 0, 'over_limit' => 0],
            'timings_ms' => [],
        ];
    }
}
