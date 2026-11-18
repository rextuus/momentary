<?php

declare(strict_types=1);

namespace App\Tests\Integration\Video\Processing;

use App\Dto\VideoFrame;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Entity\VideoScene;
use App\Entity\VideoTranscript;
use App\Enum\PersonStatus;
use App\Enum\VideoStatus;
use App\Repository\VideoFaceRepository;
use App\Service\Face\Embedding\EmbeddingIndex;
use App\Service\Face\Embedding\EmbeddingMath;
use App\Service\Face\FaceRecognitionSettingsProvider;
use App\Service\Face\InsightFace\FaceServiceClient;
use App\Service\Face\Provider\FaceRecognitionProviders;
use App\Service\Face\Provider\InsightFaceProvider;
use App\Service\Gemini\GeminiService;
use App\Service\Transcription\VideoTranscriber;
use App\Service\Video\Analyze\ChapterGenerator;
use App\Service\Video\Analyze\JellyfinUploader;
use App\Service\Video\Analyze\Result\ChapterGenerationResult;
use App\Service\Video\Analyze\Result\JellyfinExportResult;
use App\Service\Video\Analyze\Result\TaggingResult;
use App\Service\Video\Analyze\TaggingService;
use App\Service\Video\Processing\Message\FrameAnalyze\Video\AnalyzeFirstVideoFrameStepMessage;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * Gesichtserkennung mit InsightFace (Standard in Produktion) durch die echte Pipeline: Frame-Messages → Provider
 * (face-service per MockHttpClient) → Abgleich gegen die Referenzen einer bekannten Person → VideoFace mit
 * Kandidaten → Konsolidierung im Pipeline-Schritt. Gemini, Jellyfin und die Transkription sind ersetzt.
 */
final class InsightFacePipelineTest extends VideoPipelineTestCase
{
    private const string MODEL = 'buffalo_l/w600k_r50';

    private string $frameDir;

    /** @var array<string, list<array<string, mixed>>> Dateiname des Frames => Gesichter, die der Dienst meldet */
    private array $facesByFrame = [];

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();

        $http = new MockHttpClient(function (string $method, string $url, array $options): JsonMockResponse {
            $body = (string) $options['body'];
            $faces = [];
            foreach ($this->facesByFrame as $frame => $frameFaces) {
                if (str_contains($body, 'filename="' . $frame . '"')) {
                    $faces = $frameFaces;
                }
            }

            return new JsonMockResponse([
                'model' => self::MODEL,
                'width' => 720,
                'height' => 576,
                'det_size' => 640,
                'faces' => $faces,
                'filtered' => ['too_small' => 0, 'over_limit' => 0],
            ]);
        });
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

        // Schritte nach der Gesichtserkennung, die externe Dienste bräuchten
        $index = $this->createStub(Indexes::class);
        $meili = $this->createStub(Client::class);
        $meili->method('index')->willReturn($index);
        $container->set(Client::class, $meili);
        $tagging = $this->createStub(TaggingService::class);
        $tagging->method('tagScene')->willReturn(new TaggingResult(true, true, []));
        $container->set(TaggingService::class, $tagging);
        $gemini = $this->createStub(GeminiService::class);
        $gemini->method('suggestVideoName')->willReturn('Geburtstag');
        $container->set(GeminiService::class, $gemini);
        $chapters = $this->createStub(ChapterGenerator::class);
        $chapters->method('generateChapters')->willReturn(new ChapterGenerationResult(true, 0));
        $container->set(ChapterGenerator::class, $chapters);
        $jellyfin = $this->createStub(JellyfinUploader::class);
        $jellyfin->method('exportVideo')->willReturn(new JellyfinExportResult(true, 'test', '1'));
        $container->set(JellyfinUploader::class, $jellyfin);
        $transcriber = $this->createStub(VideoTranscriber::class);
        $transcriber
            ->method('transcribe')
            ->willReturnCallback(static function (): VideoTranscript {
                $transcript = new VideoTranscript(new Video());
                $transcript->start('large-v3-turbo', 10.0);
                $transcript->complete('de', []);

                return $transcript;
            });
        $container->set(VideoTranscriber::class, $transcriber);

        $this->frameDir = sys_get_temp_dir() . '/insightface-pipeline-' . uniqid();
        $this->trackCreatedPath($this->frameDir);
    }

    public function testKnownPersonIsRecognizedAndStrangerIsGroupedByConsolidation(): void
    {
        // Anna ist aus einem anderen Video bekannt (benannt, gutes Referenzgesicht)
        $anna = PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        $reference = VideoFaceFactory::createOne([
            'person' => $anna,
            'video' => VideoFactory::createOne(),
            'faceLabel' => 'anna-reference',
            'provider' => 'insightface',
            'detectionScore' => 95.0,
            'faceSizePx' => 120,
        ]);
        $reference->setEmbedding(self::axis(0), self::MODEL);

        $video = $this->videoInFaceAnalysis();
        // Frame 1: Anna (Kosinus 0,9 zur Referenz) und ein Fremder; Frame 2: der Fremde an anderer Stelle, nur
        // Kosinus 0,40 – unter „Sofort zuordnen“ (0,50), aber über „Im Video gruppieren“ (0,35)
        $this->facesByFrame = [
            'frame_0001.jpg' => [
                self::face(self::mix(0, 1, 0.9), [100, 100, 220, 260]),
                self::face(self::axis(2), [400, 100, 520, 260]),
            ],
            'frame_0002.jpg' => [self::face(self::mix(2, 3, 0.4), [100, 300, 220, 460])],
            'frame_0003.jpg' => [],
        ];

        $output = $this->dispatch(new AnalyzeFirstVideoFrameStepMessage(self::idOf($video), 0, 'INITIAL')
            ->setCurrentFrame(new VideoFrame($this->frame('frame_0001.jpg'), 1))
            ->setRemainingFrames([
                new VideoFrame($this->frame('frame_0002.jpg'), 2),
                new VideoFrame($this->frame('frame_0003.jpg'), 7),
            ]));

        $updated = $this->reload($video);
        self::assertSame(VideoStatus::COMPLETED, $updated->getStatus(), $output . (string) $updated->getErrorMessage());

        $faces = self::getContainer()
            ->get(VideoFaceRepository::class)
            ->findBy(['medium' => $updated], ['timestamp' => 'ASC', 'id' => 'ASC']);
        self::assertCount(3, $faces);
        [$annaFace, $stranger, $strangerLater] = $faces;

        // Abgleich gegen Annas Referenz: sofort zugeordnet, Kandidat gespeichert
        self::assertSame($anna->getId(), $annaFace->getPerson()?->getId());
        self::assertEqualsWithDelta(90.0, $annaFace->getMatchCandidates()['anna-reference'] ?? 0.0, 0.1);
        foreach ($faces as $face) {
            self::assertSame('insightface', $face->getProvider());
            self::assertSame(self::MODEL, $face->getEmbeddingModel());
            self::assertNull($face->getEmotion());
        }

        // Der Fremde: im zweiten Frame nur Kandidat (0,40), erst die Konsolidierung legt beide zusammen
        self::assertEqualsWithDelta(
            40.0,
            $strangerLater->getMatchCandidates()[(string) $stranger->getFaceLabel()] ?? 0.0,
            0.1,
        );
        self::assertNotSame($anna->getId(), $stranger->getPerson()?->getId());
        self::assertSame(
            $stranger->getPerson()?->getId(),
            $strangerLater->getPerson()?->getId(),
            'nach Konsolidierung eine Person',
        );
        self::assertSame(
            1,
            (int) self::getContainer()
                ->get(Connection::class)
                ->fetchOne("SELECT COUNT(*) FROM person WHERE status = 'new'"),
            'die vorher zweite automatische Person ist aufgeräumt',
        );
    }

    /**
     * Zwei Szenen, nur die erste mit Gesichtern: Die Pipeline legt die leere Szene beim Zusammenführen an (Schritt 18,
     * mit Zeilensperre auf MySQL, ohne auf SQLite) und läuft bis zum Ende.
     */
    public function testSceneWithoutFacesIsMergedAndPipelineCompletes(): void
    {
        $video = $this->videoInFaceAnalysis([[0.0, 5.0], [5.0, 10.0]]);
        $this->facesByFrame = [
            'frame_0001.jpg' => [self::face(self::axis(0), [100, 100, 220, 260])],
            'frame_0002.jpg' => [self::face(self::mix(0, 1, 0.95), [110, 100, 230, 260])],
            'frame_0003.jpg' => [],
        ];

        $output = $this->dispatch(new AnalyzeFirstVideoFrameStepMessage(self::idOf($video), 0, 'INITIAL')
            ->setCurrentFrame(new VideoFrame($this->frame('frame_0001.jpg'), 1))
            ->setRemainingFrames([
                new VideoFrame($this->frame('frame_0002.jpg'), 2),
                new VideoFrame($this->frame('frame_0003.jpg'), 7),
            ]));

        $updated = $this->reload($video);
        self::assertSame(VideoStatus::COMPLETED, $updated->getStatus(), $output . (string) $updated->getErrorMessage());
        self::assertCount(1, $updated->getScenes(), 'leere Szene ist aufgegangen');
        $scene = $updated->getScenes()->first();
        self::assertNotFalse($scene);
        self::assertSame(10.0, $scene->getEndSeconds());
        $faces = self::getContainer()->get(VideoFaceRepository::class)->findBy(['medium' => $updated]);
        self::assertCount(2, $faces);
        self::assertSame(
            $faces[0]->getPerson()?->getId(),
            $faces[1]->getPerson()?->getId(),
            'Kosinus 0,95: sofort zugeordnet',
        );
    }

    /**
     * @param list<array{float, float}> $scenes
     */
    private function videoInFaceAnalysis(array $scenes = [[0.0, 10.0]]): Video
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $video = new Video();
        $video->setTitle('Geburtstag 1994');
        $video->setCreatedAt(new \DateTimeImmutable());
        $video->setStatus(VideoStatus::ANALYZING_FACES_INITIAL);
        $video->setDuration(10.0);
        // Erstanalyse schon so dicht wie das Refinement: das Refinement wird übersprungen
        $video->setAnalysisFps(1.0);
        $video->setRefinedAnalysisFps(1.0);
        $video->setCurrentFrameDirectory($this->frameDir . '/analysis');
        $em->persist($video);
        foreach ($scenes as $i => [$start, $end]) {
            $scene = new VideoScene();
            $scene->setSceneNumber($i + 1);
            $scene->setStartSeconds($start);
            $scene->setEndSeconds($end);
            $video->addScene($scene);
            $em->persist($scene);
        }
        $em->flush();
        // Gesichtsbilder landen im Test-Storage unter images/faces/<videoId>
        $this->trackCreatedPath(
            self::getContainer()->getParameter('kernel.project_dir') . '/var/storage/images/faces/' . $video->getId(),
        );

        return $video;
    }

    private function frame(string $name): string
    {
        $path = $this->frameDir . '/analysis/' . $name;
        new Filesystem()->dumpFile($path, 'jpeg');

        return $path;
    }

    /**
     * @param list<float>                       $embedding
     * @param array{int, int, int, int}         $box
     *
     * @return array<string, mixed>
     */
    private static function face(array $embedding, array $box): array
    {
        return [
            'box' => $box,
            'score' => 0.9,
            'landmarks' => [],
            'embedding' => $embedding,
            'age' => 30,
            'gender' => 'F',
        ];
    }

    /**
     * @return list<float> Einheitsvektor in Richtung $axis (512 Werte)
     */
    private static function axis(int $axis): array
    {
        $vector = array_fill(0, 512, 0.0);
        $vector[$axis] = 1.0;

        return array_values($vector);
    }

    /**
     * @return list<float> Vektor mit Kosinus $cosine zu axis($axis)
     */
    private static function mix(int $axis, int $other, float $cosine): array
    {
        $vector = self::axis($axis);
        $vector[$axis] = $cosine;
        $vector[$other] = sqrt(1 - $cosine ** 2);

        return EmbeddingMath::normalize(array_values($vector));
    }
}
