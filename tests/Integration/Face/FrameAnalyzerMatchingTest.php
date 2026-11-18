<?php

declare(strict_types=1);

namespace App\Tests\Integration\Face;

use App\Entity\FaceRecognitionSettings;
use App\Entity\Person;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Enum\PersonStatus;
use App\Repository\VideoFaceRepository;
use App\Service\Aws\AmazonRekognitionService;
use App\Service\Face\DetectedFace;
use App\Service\Face\FaceRecognitionSettingsProvider;
use App\Service\ImageFileService;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Analyze\FrameAnalyzer;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoFactory;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem as LocalFs;

final class FrameAnalyzerMatchingTest extends KernelTestCase
{
    private AmazonRekognitionService&Stub $rekognition;
    private string $tmpDir;
    private string $frame;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->tmpDir = sys_get_temp_dir() . '/frame-analyzer-' . uniqid();
        new LocalFs()->mkdir($this->tmpDir);
        $this->frame = $this->tmpDir . '/frame_0001.jpg';
        file_put_contents($this->frame, 'jpeg');

        $this->rekognition = $this->createStub(AmazonRekognitionService::class);
        $container->set(AmazonRekognitionService::class, $this->rekognition);
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

    public function testFaceAboveMatchThresholdJoinsExistingPerson(): void
    {
        $anna = $this->knownFace('aws-anna', 'Anna', gender: 'Female');
        $video = VideoFactory::createOne();
        $this->facesInFrame(self::detected('aws-new', ['aws-anna' => 93.0], gender: 'Female'));

        $this->analyze($video);

        $face = $this->face('aws-new');
        self::assertSame($anna->getId(), $face->getPerson()?->getId());
        self::assertSame(['aws-anna' => 93.0], $face->getMatchCandidates());
        self::assertSame(93.0, $face->getMatchSimilarity());
    }

    public function testFaceBelowMatchThresholdBecomesNewPersonWithSuggestion(): void
    {
        $anna = $this->knownFace('aws-anna', 'Anna', status: PersonStatus::IDENTIFIED);
        $video = VideoFactory::createOne();
        $this->facesInFrame(self::detected('aws-new', ['aws-anna' => 84.0]));

        $this->analyze($video);

        $face = $this->face('aws-new');
        self::assertNotSame($anna->getId(), $face->getPerson()?->getId());
        self::assertSame('unknown_aws-new', $face->getPerson()?->getName());
        self::assertSame($anna->getId(), $face->getDetection()?->getId(), 'Identifizierte Person als Vorschlag');
    }

    public function testSecondFaceInSameFrameCannotJoinTheSamePerson(): void
    {
        $anna = $this->knownFace('aws-anna', 'Anna');
        $video = VideoFactory::createOne();
        $this->facesInFrame(
            self::detected('aws-1', ['aws-anna' => 96.0]),
            self::detected('aws-2', ['aws-anna' => 94.0]),
        );

        $this->analyze($video);

        self::assertSame($anna->getId(), $this->face('aws-1')->getPerson()?->getId());
        self::assertNotSame($anna->getId(), $this->face('aws-2')->getPerson()?->getId());
    }

    public function testNextBestPersonIsUsedWhenTheBestIsAlreadyInTheFrame(): void
    {
        $anna = $this->knownFace('aws-anna', 'Anna');
        $ben = $this->knownFace('aws-ben', 'Ben');
        $video = VideoFactory::createOne();
        $this->facesInFrame(
            self::detected('aws-1', ['aws-anna' => 97.0]),
            self::detected('aws-2', ['aws-anna' => 95.0, 'aws-ben' => 92.0]),
        );

        $this->analyze($video);

        self::assertSame($anna->getId(), $this->face('aws-1')->getPerson()?->getId());
        self::assertSame($ben->getId(), $this->face('aws-2')->getPerson()?->getId());
    }

    public function testDifferentGenderNeedsVeryHighSimilarity(): void
    {
        $this->knownFace('aws-anna', 'Anna', gender: 'Female');
        $video = VideoFactory::createOne();
        $this->facesInFrame(self::detected('aws-new', ['aws-anna' => 92.0], gender: 'Male'));

        $this->analyze($video);

        self::assertSame('unknown_aws-new', $this->face('aws-new')->getPerson()?->getName());
    }

    public function testMatchThresholdComesFromSettings(): void
    {
        $settings = new FaceRecognitionSettings()->setMatchThreshold(80.0);
        self::getContainer()->get(FaceRecognitionSettingsProvider::class)->save($settings);

        $anna = $this->knownFace('aws-anna', 'Anna');
        $video = VideoFactory::createOne();
        $this->facesInFrame(self::detected('aws-new', ['aws-anna' => 84.0]));

        $this->analyze($video);

        self::assertSame($anna->getId(), $this->face('aws-new')->getPerson()?->getId());
    }

    public function testMergedPersonsForwardToTheirTarget(): void
    {
        $target = PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        $merged = PersonFactory::createOne([
            'name' => 'Anna alt',
            'status' => PersonStatus::MERGED,
            'mergedInto' => $target,
        ]);
        VideoFaceFactory::createOne(['person' => $merged, 'faceLabel' => 'aws-old']);
        $video = VideoFactory::createOne();
        $this->facesInFrame(self::detected('aws-new', ['aws-old' => 95.0]));

        $this->analyze($video);

        self::assertSame($target->getId(), $this->face('aws-new')->getPerson()?->getId());
    }

    public function testRefinementFramesOfTheCurrentRunAreAnalyzed(): void
    {
        $video = VideoFactory::createOne(['currentFrameDirectory' => $this->tmpDir . '/frames/analysis']);
        $refinementFrame = $this->tmpDir . '/frames/refinement/scene_3/frame_0001.jpg';
        new LocalFs()->dumpFile($refinementFrame, 'jpeg');
        $this->facesInFrame(self::detected('aws-new'));

        self::assertTrue(
            self::getContainer()
                ->get(FrameAnalyzer::class)
                ->analyzeFrame((int) $video->getId(), $refinementFrame, 12),
        );
    }

    public function testFramesOfAnOlderRunAreIgnored(): void
    {
        $video = VideoFactory::createOne(['currentFrameDirectory' => $this->tmpDir . '/frames/analysis']);
        $this->facesInFrame(self::detected('aws-new'));

        // $this->frame liegt außerhalb von .../frames/
        self::assertFalse(
            self::getContainer()
                ->get(FrameAnalyzer::class)
                ->analyzeFrame((int) $video->getId(), $this->frame, 12),
        );
    }

    private function analyze(Video $video): void
    {
        self::assertTrue(
            self::getContainer()
                ->get(FrameAnalyzer::class)
                ->analyzeFrame((int) $video->getId(), $this->frame, 12),
        );
        self::getContainer()->get('doctrine')->getManager()->clear();
    }

    private function facesInFrame(DetectedFace ...$faces): void
    {
        $this->rekognition->method('processAllFacesInImage')->willReturn(array_values($faces));
    }

    private function knownFace(
        string $label,
        string $name,
        string $gender = 'Female',
        PersonStatus $status = PersonStatus::NEW,
    ): Person {
        $person = PersonFactory::createOne(['name' => $name, 'status' => $status]);
        VideoFaceFactory::createOne(['person' => $person, 'faceLabel' => $label, 'gender' => $gender]);

        return $person;
    }

    private function face(string $label): VideoFace
    {
        $face = self::getContainer()->get(VideoFaceRepository::class)->findOneBy(['faceLabel' => $label]);
        self::assertNotNull($face);

        return $face;
    }

    /**
     * @param array<string, float> $candidates
     */
    private static function detected(string $faceId, array $candidates = [], string $gender = 'Female'): DetectedFace
    {
        return new DetectedFace(
            $faceId,
            ['Left' => 0.4, 'Top' => 0.3, 'Width' => 0.1, 'Height' => 0.15],
            99.0,
            30,
            $gender,
            'HAPPY',
            $candidates,
        );
    }
}
