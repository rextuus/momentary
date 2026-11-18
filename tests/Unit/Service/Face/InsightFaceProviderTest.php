<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Face;

use App\Entity\FaceRecognitionSettings;
use App\Entity\Video;
use App\Service\Face\Embedding\EmbeddingIndex;
use App\Service\Face\FaceRecognitionSettingsProvider;
use App\Service\Face\InsightFace\FaceServiceClient;
use App\Service\Face\Provider\InsightFaceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class InsightFaceProviderTest extends TestCase
{
    private string $image;

    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        $this->image = tempnam(sys_get_temp_dir(), 'face') ?: throw new \RuntimeException('tempnam');
        file_put_contents($this->image, 'jpeg');
    }

    protected function tearDown(): void
    {
        if (is_file($this->image)) {
            unlink($this->image);
        }
    }

    public function testAnalyzesWithFiltersFromSettingsAndScalesCandidates(): void
    {
        $settings = new FaceRecognitionSettings()
            ->setInsightFaceMinFacePx(48)
            ->setInsightFaceMaxFaces(5)
            ->setInsightFaceCandidateThreshold(0.3)
            ->setMaxCandidates(7);
        $index = $this->createMock(EmbeddingIndex::class);
        $index
            ->expects(self::once())
            ->method('candidates')
            ->with([0.6, 0.8], 42, 'buffalo_l/w600k_r50', 0.3, 7, 1994)
            ->willReturn(['alt' => 0.8123]);
        $index->expects(self::once())->method('remember');

        $faces = $this->provider([self::analysis([self::face()])], $settings, $index)->detectFaces(
            $this->image,
            self::video(),
        );

        self::assertSame(['http://face-service.test/analyze?min_face_px=48&max_faces=5'], $this->urls);
        self::assertCount(1, $faces);
        self::assertSame(['alt' => 81.23], $faces[0]->candidates, 'Kosinus × 100');
        self::assertSame(91.0, $faces[0]->confidence);
        self::assertSame('Male', $faces[0]->gender);
        self::assertNull($faces[0]->emotion);
        self::assertSame('buffalo_l/w600k_r50', $faces[0]->embeddingModel);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $faces[0]->faceId);
    }

    public function testPrefilterWithoutFacesSkipsTheExpensiveAnalysis(): void
    {
        $faces = $this->provider([self::analysis([])], new FaceRecognitionSettings(), prefilter: 320)->detectFaces(
            $this->image,
            self::video(),
        );

        self::assertSame([], $faces);
        self::assertSame(['http://face-service.test/detect?det_size=320&min_face_px=40&max_faces=10'], $this->urls);
    }

    public function testPrefilterWithFacesRunsTheAnalysis(): void
    {
        $index = $this->createStub(EmbeddingIndex::class);
        $index->method('candidates')->willReturn([]);

        $faces = $this->provider(
            [self::analysis([self::face(withEmbedding: false)]), self::analysis([self::face()])],
            new FaceRecognitionSettings(),
            $index,
            prefilter: 320,
        )->detectFaces($this->image, self::video());

        self::assertCount(1, $faces);
        self::assertCount(2, $this->urls);
        self::assertStringContainsString('/analyze?', $this->urls[1]);
    }

    /**
     * @param list<array<string, mixed>> $responses
     */
    private function provider(
        array $responses,
        FaceRecognitionSettings $settings,
        ?EmbeddingIndex $index = null,
        int $prefilter = 0,
    ): InsightFaceProvider {
        $http = new MockHttpClient(function (string $method, string $url) use (&$responses): JsonMockResponse {
            $this->urls[] = $url;

            return new JsonMockResponse(array_shift($responses));
        });
        $settingsProvider = $this->createStub(FaceRecognitionSettingsProvider::class);
        $settingsProvider->method('get')->willReturn($settings);

        return new InsightFaceProvider(
            new FaceServiceClient($http, 'http://face-service.test'),
            $index ?? $this->createStub(EmbeddingIndex::class),
            $settingsProvider,
            $prefilter,
        );
    }

    private static function video(): Video
    {
        $video = new Video();
        new \ReflectionProperty($video, 'id')->setValue($video, 42);
        $video->setTakenAt(new \DateTimeImmutable('1994-07-02 15:00:00', new \DateTimeZone('UTC')));

        return $video;
    }

    /**
     * @return array<string, mixed>
     */
    private static function face(bool $withEmbedding = true): array
    {
        return [
            'box' => [10, 10, 110, 130],
            'score' => 0.91,
            'landmarks' => [],
            ...($withEmbedding ? ['embedding' => [0.6, 0.8], 'age' => 40, 'gender' => 'M'] : []),
        ];
    }

    /**
     * @param list<array<string, mixed>> $faces
     *
     * @return array<string, mixed>
     */
    private static function analysis(array $faces): array
    {
        return [
            'model' => 'buffalo_l/w600k_r50',
            'width' => 640,
            'height' => 360,
            'det_size' => 640,
            'faces' => $faces,
        ];
    }

    public function testUpscaledVideoRaisesTheMinimumFaceSizeAndStoresNativeSize(): void
    {
        // Frame 1920×1080 eines auf HD hochgerechneten PAL-Bands (576 Zeilen): Faktor 1,875
        file_put_contents($this->image, self::jpegHeader(1920, 1080));
        $video = self::video()->setSourceHeight(576);
        $index = $this->createStub(EmbeddingIndex::class);
        $index->method('candidates')->willReturn([]);

        $faces = $this->provider(
            [self::analysis([self::face()])],
            new FaceRecognitionSettings()->setInsightFaceMinFacePx(40),
            $index,
        )->detectFaces($this->image, $video);

        self::assertStringContainsString('min_face_px=75', $this->urls[0], '40 native Pixel × 1,875');
        self::assertSame(53, $faces[0]->sizePx, 'kürzere Seite 100 px / 1,875, in nativen Pixeln');
    }

    public function testOriginalResolutionNeedsNoScaling(): void
    {
        file_put_contents($this->image, self::jpegHeader(1024, 576));

        self::assertSame(1.0, InsightFaceProvider::upscaleFactor(self::video()->setSourceHeight(576), $this->image));
        self::assertSame(1.0, InsightFaceProvider::upscaleFactor(self::video(), $this->image), 'ohne sourceHeight');
    }

    /**
     * Kleinstmögliches JPEG, aus dem getimagesize() die Größe liest (SOI, SOF0, EOI); GD ist im Image nicht installiert.
     */
    private static function jpegHeader(int $width, int $height): string
    {
        return (
            "\xFF\xD8\xFF\xC0\x00\x11\x08"
            . pack('nn', $height, $width)
            . "\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01\xFF\xD9"
        );
    }
}
