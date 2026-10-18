<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Aws;

use App\Entity\FaceRecognitionSettings;
use App\Service\Aws\AmazonRekognitionService;
use App\Service\Face\FaceRecognitionSettingsProvider;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Rekognition\Exception\RekognitionException;
use Aws\Rekognition\RekognitionClient;
use Aws\Result;
use PHPUnit\Framework\TestCase;

final class AmazonRekognitionServiceTest extends TestCase
{
    /** @var list<CommandInterface> */
    private array $commands = [];
    private string $image;

    protected function setUp(): void
    {
        $this->image = (string) tempnam(sys_get_temp_dir(), 'frame');
        file_put_contents($this->image, 'jpeg-bytes');
    }

    protected function tearDown(): void
    {
        if (is_file($this->image)) {
            unlink($this->image);
        }
    }

    public function testEveryIndexedFaceIsSearchedIndividually(): void
    {
        $service = $this->service([
            new Result(['FaceRecords' => [
                self::record('face-a', width: 0.12, gender: 'Female', age: [30, 40]),
                self::record('face-b', width: 0.10, gender: 'Male', age: [5, 9]),
            ]]),
            new Result([
                'FaceMatches' => [
                    ['Similarity' => 84.5, 'Face' => ['FaceId' => 'old-1']],
                    ['Similarity' => 97.0, 'Face' => ['FaceId' => 'old-2']],
                ],
            ]),
            new Result(['FaceMatches' => []]),
        ]);

        $faces = $service->processAllFacesInImage($this->image);

        self::assertSame(['IndexFaces', 'SearchFaces', 'SearchFaces'], $this->commandNames());
        self::assertSame('face-a', $this->commands[1]['FaceId']);
        self::assertSame('face-b', $this->commands[2]['FaceId']);

        self::assertCount(2, $faces);
        self::assertSame('face-a', $faces[0]->faceId);
        self::assertSame(
            ['old-2' => 97.0, 'old-1' => 84.5],
            $faces[0]->candidates,
            'Kandidaten absteigend nach Ähnlichkeit',
        );
        self::assertSame(35, $faces[0]->age);
        self::assertSame('Female', $faces[0]->gender);
        self::assertSame('HAPPY', $faces[0]->emotion);
        self::assertSame([], $faces[1]->candidates);
    }

    public function testSettingsAreSentToRekognition(): void
    {
        $settings = new FaceRecognitionSettings()
            ->setQualityFilter('MEDIUM')
            ->setMaxFacesPerFrame(7)
            ->setCandidateThreshold(65.0)
            ->setMaxCandidates(4);

        $service = $this->service([
            new Result(['FaceRecords' => [self::record('face-a', width: 0.2)]]),
            new Result(['FaceMatches' => []]),
        ], $settings);
        $service->processAllFacesInImage($this->image);

        self::assertSame('MEDIUM', $this->commands[0]['QualityFilter']);
        self::assertSame(7, $this->commands[0]['MaxFaces']);
        self::assertSame(['ALL'], $this->commands[0]['DetectionAttributes']);
        self::assertSame(65.0, $this->commands[1]['FaceMatchThreshold']);
        self::assertSame(4, $this->commands[1]['MaxFaces']);
    }

    public function testTooSmallFacesAreRemovedFromTheCollection(): void
    {
        $service = $this->service([
            new Result(['FaceRecords' => [
                self::record('big', width: 0.15),
                self::record('tiny-1', width: 0.01),
                self::record('tiny-2', width: 0.02),
            ]]),
            new Result(['FaceMatches' => []]),
            new Result(['DeletedFaces' => ['tiny-1', 'tiny-2']]),
        ]);

        $faces = $service->processAllFacesInImage($this->image);

        self::assertSame(['big'], array_map(static fn($face) => $face->faceId, $faces));
        self::assertSame(['IndexFaces', 'SearchFaces', 'DeleteFaces'], $this->commandNames());
        self::assertSame(['tiny-1', 'tiny-2'], $this->commands[2]['FaceIds']);
    }

    public function testImageWithoutUsableFacesNeedsOnlyOneCall(): void
    {
        $service = $this->service([new Result([
            'FaceRecords' => [],
            'UnindexedFaces' => [['Reasons' => ['LOW_SHARPNESS']]],
        ])]);

        self::assertSame([], $service->processAllFacesInImage($this->image));
        self::assertSame(['IndexFaces'], $this->commandNames());
    }

    public function testCountIndexedFaces(): void
    {
        $service = $this->service([new Result(['FaceCount' => 42])]);

        self::assertSame(42, $service->countIndexedFaces());
        self::assertSame(['DescribeCollection'], $this->commandNames());
    }

    public function testCountIndexedFacesIsNullWhenCollectionDoesNotExist(): void
    {
        $handler = new MockHandler();
        $handler->append(static fn(CommandInterface $command) => new RekognitionException('not found', $command, [
            'code' => 'ResourceNotFoundException',
        ]));

        self::assertNull($this->serviceWithHandler($handler)->countIndexedFaces());
    }

    /**
     * @param list<Result> $results
     */
    private function service(array $results, ?FaceRecognitionSettings $settings = null): AmazonRekognitionService
    {
        $handler = new MockHandler();
        foreach ($results as $result) {
            $handler->append(function (CommandInterface $command) use ($result): Result {
                $this->commands[] = $command;

                return $result;
            });
        }

        return $this->serviceWithHandler($handler, $settings);
    }

    private function serviceWithHandler(
        MockHandler $handler,
        ?FaceRecognitionSettings $settings = null,
    ): AmazonRekognitionService {
        $client = new RekognitionClient([
            'region' => 'eu-central-1',
            'version' => 'latest',
            // @mago-expect lint:no-literal-password
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
        ]);

        $provider = $this->createStub(FaceRecognitionSettingsProvider::class);
        $provider->method('get')->willReturn($settings ?? new FaceRecognitionSettings());

        return new AmazonRekognitionService('test', 'test', 'eu-central-1', $provider, $client);
    }

    /**
     * @return list<string>
     */
    private function commandNames(): array
    {
        return array_map(static fn(CommandInterface $command): string => $command->getName(), $this->commands);
    }

    /**
     * @param array{int, int} $age
     *
     * @return array<string, mixed>
     */
    private static function record(string $faceId, float $width, string $gender = 'Male', array $age = [20, 30]): array
    {
        return [
            'Face' => [
                'FaceId' => $faceId,
                'Confidence' => 99.1,
                'BoundingBox' => ['Left' => 0.3, 'Top' => 0.2, 'Width' => $width, 'Height' => $width * 1.3],
            ],
            'FaceDetail' => [
                'AgeRange' => ['Low' => $age[0], 'High' => $age[1]],
                'Gender' => ['Value' => $gender, 'Confidence' => 90.0],
                'Emotions' => [
                    ['Type' => 'CALM', 'Confidence' => 20.0],
                    ['Type' => 'HAPPY', 'Confidence' => 75.0],
                ],
            ],
        ];
    }
}
