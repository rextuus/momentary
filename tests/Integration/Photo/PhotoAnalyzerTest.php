<?php

declare(strict_types=1);

namespace App\Tests\Integration\Photo;

use App\Entity\Person;
use App\Entity\Photo;
use App\Enum\FilePurpose;
use App\Enum\PhotoStatus;
use App\Service\Aws\AmazonRekognitionService;
use App\Service\Aws\DetectedFace;
use App\Service\Gemini\GeminiService;
use App\Service\Photo\PhotoAnalyzer;
use App\Service\Photo\PhotoProcessor;
use App\Service\Storage\StoragePathProvider;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\PhotoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PhotoAnalyzerTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();

        $processor = $this->createStub(PhotoProcessor::class);
        $processor
            ->method('process')
            ->willReturnCallback(static function (string $source, string $display, string $thumbnail): array {
                new Filesystem()->dumpFile($display, 'display');
                new Filesystem()->dumpFile($thumbnail, 'thumb');

                return [
                    'width' => 4032,
                    'height' => 3024,
                    'takenAt' => new \DateTimeImmutable('2025-03-19 12:35:38', new \DateTimeZone('UTC')),
                    'latitude' => 39.57,
                    'longitude' => 2.65,
                    'camera' => 'Apple iPhone 13',
                ];
            });

        $gemini = $this->createStub(GeminiService::class);
        $gemini
            ->method('analyzeImage')
            ->willReturn([
                'Titel' => 'Mittagessen auf der Terrasse',
                'Tags' => ['Orte' => ['Terrasse'], 'Aktivitäten' => ['Essen']],
            ]);

        $index = $this->createStub(Indexes::class);
        $index->method('getFilterableAttributes')->willReturn(['tags', 'persons', 'takenAt']);
        $meili = $this->createStub(Client::class);
        $meili->method('getIndex')->willReturn($index);

        $container = self::getContainer();
        $container->set(PhotoProcessor::class, $processor);
        $container->set(GeminiService::class, $gemini);
        $container->set(Client::class, $meili);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove(self::getContainer()->get(StoragePathProvider::class)->getAbsoluteFilePath('photos'));
        parent::tearDown();
    }

    public function testAnalysisFillsMetadataFacesAndTags(): void
    {
        $photo = $this->photo('20250319_133537');

        $this->analyzer()->analyze($photo);

        $photo = $this->reload($photo);
        self::assertSame(PhotoStatus::COMPLETED, $photo->getStatus());
        self::assertSame('Mittagessen auf der Terrasse', $photo->getTitle(), 'Kamera-Dateiname wird ersetzt');
        self::assertSame('2025-03-19 12:35', $photo->getTakenAt()?->format('Y-m-d H:i'));
        self::assertSame([4032, 3024], [$photo->getWidth(), $photo->getHeight()]);
        self::assertTrue($photo->hasLocation());
        self::assertSame('Apple iPhone 13', $photo->getCamera());
        self::assertNotNull($photo->getDisplayFile());
        self::assertSame(FilePurpose::PHOTO_THUMBNAIL, $photo->getThumbnailFile()?->getPurpose());

        self::assertCount(2, $photo->getFaces());
        foreach ($photo->getFaces() as $face) {
            self::assertSame(
                $photo->getDisplayFile()?->getId(),
                $face->getFaceImage()?->getId(),
                'Foto selbst dient als Gesichtsbild',
            );
            self::assertNull($face->getVideo());
            self::assertSame($photo, $face->getPhoto());
        }
        $persons = array_unique(array_map(static fn($f) => $f->getPerson()?->getId(), $photo->getFaces()->toArray()));
        self::assertCount(2, $persons, 'zwei Gesichter im selben Bild sind zwei Personen');

        self::assertEqualsCanonicalizing(
            ['Terrasse', 'Essen'],
            array_map(static fn($t) => $t->getName(), $photo->getTags()->toArray()),
        );
    }

    public function testManualTitleIsKept(): void
    {
        $photo = $this->photo('Omas Geburtstag');

        $this->analyzer()->analyze($photo);

        self::assertSame('Omas Geburtstag', $this->reload($photo)->getTitle());
    }

    public function testReanalysisReplacesFacesAndCleansUpAws(): void
    {
        $rekognition = $this->createMock(AmazonRekognitionService::class);
        $rekognition->method('processAllFacesInImage')->willReturn(self::detectedFaces());
        $rekognition->expects(self::once())->method('deleteFaces')->with(['aws-a', 'aws-b']);

        $photo = $this->photo('IMG_0001');
        $analyzer = $this->analyzer($rekognition);
        $analyzer->analyze($photo);
        $analyzer->analyze($this->reload($photo));

        $photo = $this->reload($photo);
        self::assertCount(2, $photo->getFaces(), 'keine doppelten Gesichter');
        self::assertSame(
            2,
            self::getContainer()->get(EntityManagerInterface::class)->getRepository(Person::class)->count([]),
            'Personen der alten Gesichter wurden aufgeräumt',
        );
    }

    public function testMissingOriginalMarksPhotoAsFailed(): void
    {
        $photo = PhotoFactory::createOne(['sourceFile' => FileFactory::new([
            'relativePath' => 'photos/does-not-exist.jpg',
        ])]);

        $this->analyzer()->analyze($photo);

        $photo = $this->reload($photo);
        self::assertSame(PhotoStatus::ERROR, $photo->getStatus());
        self::assertStringContainsString('Originaldatei', (string) $photo->getErrorMessage());
    }

    /**
     * AWS wird erst hier ersetzt, damit ein Test statt des Stubs einen Mock mit Erwartungen nutzen kann.
     */
    private function analyzer(?AmazonRekognitionService $rekognition = null): PhotoAnalyzer
    {
        if ($rekognition === null) {
            $rekognition = $this->createStub(AmazonRekognitionService::class);
            $rekognition->method('processAllFacesInImage')->willReturn(self::detectedFaces());
        }
        self::getContainer()->set(AmazonRekognitionService::class, $rekognition);

        return self::getContainer()->get(PhotoAnalyzer::class);
    }

    /**
     * @return list<DetectedFace>
     */
    private static function detectedFaces(): array
    {
        return [
            new DetectedFace(
                'aws-a',
                ['Width' => 0.1, 'Height' => 0.15, 'Left' => 0.2, 'Top' => 0.3],
                99.0,
                30,
                'Female',
                'HAPPY',
                [],
            ),
            new DetectedFace(
                'aws-b',
                ['Width' => 0.1, 'Height' => 0.15, 'Left' => 0.6, 'Top' => 0.3],
                99.0,
                35,
                'Male',
                'CALM',
                [],
            ),
        ];
    }

    private function photo(string $title): Photo
    {
        $path = 'photos/test-' . bin2hex(random_bytes(4)) . '/original.jpg';
        new Filesystem()->dumpFile(
            self::getContainer()->get(StoragePathProvider::class)->getAbsoluteFilePath($path),
            'jpeg',
        );

        return PhotoFactory::createOne([
            'title' => $title,
            'sourceFile' => FileFactory::new(['relativePath' => $path, 'purpose' => FilePurpose::PHOTO_SOURCE]),
        ]);
    }

    private function reload(Photo $photo): Photo
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(Photo::class, $photo->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }
}
