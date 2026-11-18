<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Face;

use App\Service\Face\InsightFace\FaceServiceClient;
use App\Service\Face\InsightFace\FaceServiceException;
use App\Service\Face\InsightFace\ServiceFace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FaceServiceClientTest extends TestCase
{
    private string $image;

    protected function setUp(): void
    {
        $this->image = tempnam(sys_get_temp_dir(), 'face') ?: throw new \RuntimeException('tempnam');
        file_put_contents($this->image, 'JPEG-BYTES');
    }

    protected function tearDown(): void
    {
        if (is_file($this->image)) {
            unlink($this->image);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function analyzeResponse(): array
    {
        return [
            'model' => 'buffalo_l/w600k_r50',
            'width' => 1000,
            'height' => 500,
            'det_size' => 640,
            'faces' => [
                [
                    'box' => [100, 50, 300, 300],
                    'score' => 0.91,
                    'landmarks' => [[150, 120], [250, 120], [200, 180], [160, 240], [240, 240]],
                    'embedding' => [0.6, 0.8],
                    'age' => 41,
                    'gender' => 'F',
                ],
            ],
            'filtered' => ['too_small' => 3, 'over_limit' => 1],
            'timings_ms' => ['decode' => 4.1, 'wait' => 0.0, 'detect' => 201.5, 'embed' => 199.0],
        ];
    }

    public function testAnalyzeSendsImageAndFiltersAndParsesFaces(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (
            &$requests,
        ): JsonMockResponse {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => (string) $options['body'],
                'headers' => $options['headers'],
            ];

            return new JsonMockResponse(self::analyzeResponse());
        });

        $result = new FaceServiceClient($client, 'http://face-service:8000/')->analyze(
            $this->image,
            minFacePx: 40,
            maxFaces: 10,
        );

        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('http://face-service:8000/analyze?min_face_px=40&max_faces=10', $requests[0]['url']);
        self::assertStringContainsString('name="image"', $requests[0]['body']);
        self::assertStringContainsString('JPEG-BYTES', $requests[0]['body']);
        self::assertStringContainsString('multipart/form-data', implode("\n", $requests[0]['headers']));

        self::assertSame('buffalo_l/w600k_r50', $result->model);
        self::assertSame(3, $result->filteredTooSmall);
        self::assertSame(1, $result->filteredOverLimit);
        self::assertSame(201.5, $result->timingsMs['detect']);
        self::assertTrue($result->hasFaces());

        $face = $result->faces[0];
        self::assertSame([100.0, 50.0, 300.0, 300.0], $face->box);
        self::assertSame([0.6, 0.8], $face->embedding);
        self::assertSame(41, $face->age);
        self::assertSame('Female', $face->genderLabel());
        self::assertCount(5, $face->landmarks);
        self::assertSame(200.0, $face->shortSide());
        $box = $face->relativeBox($result->width, $result->height);
        self::assertEqualsWithDelta([0.1, 0.1, 0.2, 0.5], array_values($box), 1e-9);
    }

    public function testDetectHasNoEmbeddingAndSendsOnlyGivenOptions(): void
    {
        $url = null;
        $client = new MockHttpClient(static function (string $method, string $requestUrl) use (
            &$url,
        ): JsonMockResponse {
            $url = $requestUrl;

            return new JsonMockResponse([
                'model' => 'buffalo_l/w600k_r50',
                'width' => 640,
                'height' => 360,
                'det_size' => 320,
                'faces' => [['box' => [0, 0, 50, 60], 'score' => 0.7, 'landmarks' => []]],
                'filtered' => ['too_small' => 0, 'over_limit' => 0],
                'timings_ms' => ['detect' => 52.0],
            ]);
        });

        $result = new FaceServiceClient($client, 'http://face-service:8000')->detect($this->image, detSize: 320);

        self::assertSame('http://face-service:8000/detect?det_size=320', $url);
        self::assertSame(320, $result->detSize);
        self::assertNull($result->faces[0]->embedding);
        self::assertNull($result->faces[0]->age);
        self::assertSame('Unknown', $result->faces[0]->genderLabel());
    }

    public function testNoFaces(): void
    {
        $client = new MockHttpClient(new JsonMockResponse([
            'model' => 'm',
            'width' => 10,
            'height' => 10,
            'det_size' => 640,
            'faces' => [],
        ]));

        $result = new FaceServiceClient($client, 'http://face-service:8000')->analyze($this->image);

        self::assertFalse($result->hasFaces());
        self::assertSame(0, $result->filteredTooSmall);
    }

    public function testErrorStatusBecomesFaceServiceException(): void
    {
        $client = new MockHttpClient(new MockResponse('{"detail":"Bild konnte nicht gelesen werden"}', [
            'http_code' => 422,
        ]));

        $this->expectException(FaceServiceException::class);
        $this->expectExceptionMessage('antwortet mit 422');

        new FaceServiceClient($client, 'http://face-service:8000')->analyze($this->image);
    }

    public function testUnreachableServiceBecomesFaceServiceException(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));

        $this->expectException(FaceServiceException::class);
        $this->expectExceptionMessage('Connection refused');

        new FaceServiceClient($client, 'http://face-service:8000')->detect($this->image);
    }

    public function testBrokenResponseBecomesFaceServiceException(): void
    {
        $client = new MockHttpClient(new JsonMockResponse([
            'width' => 10,
            'height' => 10,
            'faces' => [['box' => [1, 2]]],
        ]));

        $this->expectException(FaceServiceException::class);

        new FaceServiceClient($client, 'http://face-service:8000')->analyze($this->image);
    }

    public function testMissingImageIsNotSent(): void
    {
        $client = new MockHttpClient(static fn() => throw new \LogicException('keine Anfrage erwartet'));

        $this->expectException(FaceServiceException::class);

        new FaceServiceClient($client, 'http://face-service:8000')->analyze('/gibt/es/nicht.jpg');
    }

    public function testRelativeBoxIsClampedToImage(): void
    {
        $face = new ServiceFace(box: [-20.0, -10.0, 120.0, 90.0], score: 0.9);

        self::assertSame(['Left' => 0.0, 'Top' => 0.0, 'Width' => 1.0, 'Height' => 0.9], $face->relativeBox(100, 100));
    }

    public function testHealth(): void
    {
        $client = new MockHttpClient(new JsonMockResponse(['status' => 'ok', 'model' => 'buffalo_l/w600k_r50']));

        self::assertSame('ok', new FaceServiceClient($client, 'http://face-service:8000')->health()['status']);
    }
}
