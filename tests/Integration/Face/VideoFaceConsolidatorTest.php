<?php

declare(strict_types=1);

namespace App\Tests\Integration\Face;

use App\Entity\File;
use App\Entity\Person;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Entity\VideoScene;
use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use App\Repository\VideoFaceRepository;
use App\Service\Face\VideoFaceConsolidator;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoFactory;
use App\Tests\Factory\VideoSceneFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VideoFaceConsolidatorTest extends KernelTestCase
{
    /** @var array<string, File> */
    private array $frames = [];

    public function testWeddingGuestsAreGroupedPerPersonNotIntoOne(): void
    {
        $video = VideoFactory::createOne();
        $scene = VideoSceneFactory::createOne(['video' => $video, 'startSeconds' => 0.0, 'endSeconds' => 60.0]);

        // Zwei Gäste in drei Frames; Rekognition liefert wegen der Qualität mittelmäßige, teils überkreuzte Werte
        $this->autoFace($video, $scene, 'f1', 'bride-1', [], left: 0.2);
        $this->autoFace($video, $scene, 'f1', 'uncle-1', [], left: 0.7);
        $this->autoFace($video, $scene, 'f2', 'bride-2', ['bride-1' => 84.0, 'uncle-1' => 81.0], left: 0.25);
        $this->autoFace($video, $scene, 'f2', 'uncle-2', ['uncle-1' => 83.0, 'bride-1' => 80.5], left: 0.65);
        $this->autoFace($video, $scene, 'f3', 'bride-3', ['bride-2' => 82.0], left: 0.3);

        $result = $this->consolidator()->consolidate($video);

        self::assertSame(5, $result->personsBefore);
        self::assertSame(2, $result->personsAfter);
        self::assertSamePerson('bride-1', 'bride-2', 'bride-3');
        self::assertSamePerson('uncle-1', 'uncle-2');
        self::assertNotSame($this->personOf('bride-1')->getId(), $this->personOf('uncle-1')->getId());
        self::assertSame(3, $result->removedPersons, 'leer gewordene, automatisch angelegte Personen werden gelöscht');
    }

    public function testTrackingGroupsFacesAwsCouldNotMatch(): void
    {
        $video = VideoFactory::createOne();
        $scene = VideoSceneFactory::createOne(['video' => $video, 'startSeconds' => 0.0, 'endSeconds' => 60.0]);
        $this->autoFace($video, $scene, 'f1', 'a', [], left: 0.4, timestamp: 10);
        $this->autoFace($video, $scene, 'f2', 'b', [], left: 0.41, timestamp: 15);

        $this->consolidator()->consolidate($video);

        self::assertSamePerson('a', 'b');
    }

    public function testConfirmedPersonIsKeptAndPullsInMatchingFaces(): void
    {
        $video = VideoFactory::createOne();
        $scene = VideoSceneFactory::createOne(['video' => $video]);
        $anna = PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        $this->face($video, $scene, 'f1', 'anna-1', [], $anna, left: 0.1);
        $this->autoFace($video, $scene, 'f2', 'x', ['anna-1' => 85.0], left: 0.7);

        $this->consolidator()->consolidate($video);

        self::assertSame($anna->getId(), $this->personOf('x')->getId());
        self::assertNotNull(self::getContainer()->get(PersonRepository::class)->find($anna->getId()));
    }

    public function testTwoConfirmedPersonsAreNeverMerged(): void
    {
        $video = VideoFactory::createOne();
        $scene = VideoSceneFactory::createOne(['video' => $video]);
        $anna = PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        $ben = PersonFactory::createOne(['name' => 'Ben', 'status' => PersonStatus::IDENTIFIED]);
        $this->face($video, $scene, 'f1', 'anna-1', [], $anna, left: 0.1);
        $this->face($video, $scene, 'f2', 'ben-1', ['anna-1' => 95.0], $ben, left: 0.7);

        $this->consolidator()->consolidate($video);

        self::assertSame($anna->getId(), $this->personOf('anna-1')->getId());
        self::assertSame($ben->getId(), $this->personOf('ben-1')->getId());
    }

    public function testPersonKnownFromOtherVideosWinsOverLocalAutoPerson(): void
    {
        $known = PersonFactory::createOne(['name' => 'unknown_known']);
        VideoFaceFactory::createOne(['person' => $known, 'faceLabel' => 'elsewhere', 'video' => VideoFactory::new()]);

        $video = VideoFactory::createOne();
        $scene = VideoSceneFactory::createOne(['video' => $video]);
        $this->autoFace($video, $scene, 'f1', 'local-1', [], left: 0.1);
        $this->autoFace($video, $scene, 'f2', 'local-2', ['local-1' => 88.0], left: 0.7);
        $this->autoFace($video, $scene, 'f3', 'local-3', ['local-2' => 86.0], left: 0.4);
        $this->face($video, $scene, 'f4', 'local-4', ['local-3' => 87.0], $known, left: 0.1);

        $this->consolidator()->consolidate($video);

        self::assertSame($known->getId(), $this->personOf('local-1')->getId());
        self::assertSamePerson('local-1', 'local-2', 'local-3', 'local-4');
    }

    public function testRunningTwiceChangesNothing(): void
    {
        $video = VideoFactory::createOne();
        $scene = VideoSceneFactory::createOne(['video' => $video]);
        $this->autoFace($video, $scene, 'f1', 'a', [], left: 0.1);
        $this->autoFace($video, $scene, 'f2', 'b', ['a' => 90.0], left: 0.7);

        $this->consolidator()->consolidate($video);
        $second = $this->consolidator()->consolidate($video);

        self::assertSame(0, $second->reassignedFaces);
        self::assertSame(1, $second->personsAfter);
    }

    private function consolidator(): VideoFaceConsolidator
    {
        return self::getContainer()->get(VideoFaceConsolidator::class);
    }

    /**
     * Gesicht mit eigener, automatisch angelegter Person - so wie nach der Analyse.
     *
     * @param array<string, float> $candidates
     */
    private function autoFace(
        Video $video,
        VideoScene $scene,
        string $frame,
        string $label,
        array $candidates,
        float $left,
        int $timestamp = 0,
    ): void {
        $this->face(
            $video,
            $scene,
            $frame,
            $label,
            $candidates,
            PersonFactory::createOne(['name' => 'unknown_' . $label]),
            $left,
            $timestamp,
        );
    }

    /**
     * @param array<string, float> $candidates
     */
    private function face(
        Video $video,
        VideoScene $scene,
        string $frame,
        string $label,
        array $candidates,
        Person $person,
        float $left,
        ?int $timestamp = null,
    ): void {
        $this->frames[$frame] ??= FileFactory::createOne();
        VideoFaceFactory::createOne([
            'video' => $video,
            'videoScene' => $scene,
            'person' => $person,
            'faceLabel' => $label,
            'matchCandidates' => $candidates,
            'faceImage' => $this->frames[$frame],
            // Frames 20 s auseinander, damit nur die Tracking-Tests Tracking auslösen
            'timestamp' => $timestamp ?? ((int) substr($frame, 1) * 20),
            'boundingBox' => ['Left' => $left, 'Top' => 0.3, 'Width' => 0.1, 'Height' => 0.15],
        ]);
    }

    private function personOf(string $label): Person
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $face = self::getContainer()->get(VideoFaceRepository::class)->findOneBy(['faceLabel' => $label]);
        self::assertInstanceOf(VideoFace::class, $face);
        $person = $face->getPerson();
        self::assertNotNull($person);

        return $person;
    }

    private function assertSamePerson(string ...$labels): void
    {
        $ids = array_unique(array_map(fn(string $label): ?int => $this->personOf($label)->getId(), $labels));
        self::assertCount(1, $ids, 'Erwartet dieselbe Person für ' . implode(', ', $labels));
    }
}
