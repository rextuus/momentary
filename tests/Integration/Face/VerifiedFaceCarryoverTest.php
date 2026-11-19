<?php

declare(strict_types=1);

namespace App\Tests\Integration\Face;

use App\Entity\File;
use App\Entity\Person;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Enum\PersonStatus;
use App\Repository\VideoFaceRepository;
use App\Service\Face\VerifiedFaceCarryover;
use App\Service\Face\VideoFaceConsolidator;
use App\Service\Video\VideoAnalysisReset;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Neuverarbeiten: bestätigte Gesichter gehen über Zeitpunkt und Box auf die neu erkannten Gesichter über,
 * automatisch zugeordnete werden neu entschieden.
 */
final class VerifiedFaceCarryoverTest extends KernelTestCase
{
    /** @var array<string, File> */
    private array $frames = [];

    public function testConfirmedFaceKeepsPersonAndConfirmationAfterReprocessing(): void
    {
        // Standard-Analyse 0,2 fps: Frames alle 5 s, Toleranz 2,5 s
        $video = VideoFactory::createOne(['analysisFps' => 0.2]);
        $anna = PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        // Bestätigt, aber nie umbenannt: auch diese Person darf beim Zurücksetzen nicht wegfallen
        $confirmedAuto = PersonFactory::createOne(['name' => 'unknown_kind', 'status' => PersonStatus::NEW]);
        $ben = PersonFactory::createOne(['name' => 'Ben', 'status' => PersonStatus::IDENTIFIED]);
        $this->oldFace($video, $anna, timestamp: 10, left: 0.2, verified: true);
        $this->oldFace($video, $confirmedAuto, timestamp: 30, left: 0.6, verified: true);
        $this->oldFace($video, $ben, timestamp: 50, left: 0.4, verified: false);
        $annaId = (int) $anna->getId();
        $confirmedAutoId = (int) $confirmedAuto->getId();

        $this->reset($video);
        self::assertNotNull($this->find(Person::class, $confirmedAutoId), 'bleibt bis zur Übertragung');

        // Neue Analyse: Frames liegen anders, Boxen etwas verschoben; jedes Gesicht bekommt erst eine eigene Person
        $this->newFace($video, 'f12', 'anna-new', timestamp: 12, left: 0.21);
        $this->newFace($video, 'f30', 'kind-new', timestamp: 30, left: 0.62);
        $this->newFace($video, 'f50', 'ben-new', timestamp: 50, left: 0.4);

        $carried = $this->carryover()->apply($this->reload($video));

        self::assertSame(2, $carried);
        self::assertFace('anna-new', $annaId, verified: true);
        self::assertFace('kind-new', $confirmedAutoId, verified: true);
        self::assertFalse($this->face('ben-new')->isVerified(), 'nur automatisch zugeordnet: wird neu entschieden');
        self::assertNotSame($ben->getId(), $this->face('ben-new')->getPerson()?->getId());
        self::assertSame([], $this->reload($video)->getVerifiedFaceCarryover(), 'Merkliste geleert');
    }

    public function testFacesTooFarAwayInTimeOrPositionAreNotCarriedOver(): void
    {
        $video = VideoFactory::createOne(['analysisFps' => 0.2]);
        $anna = PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        $this->oldFace($video, $anna, timestamp: 10, left: 0.2, verified: true);
        $this->oldFace($video, $anna, timestamp: 40, left: 0.2, verified: true);

        $this->reset($video);
        // 3 s daneben (> 2,5 s) bzw. andere Stelle im Bild (IoU < 0,5)
        $this->newFace($video, 'f13', 'late', timestamp: 13, left: 0.2);
        $this->newFace($video, 'f40', 'elsewhere', timestamp: 40, left: 0.27);

        self::assertSame(0, $this->carryover()->apply($this->reload($video)));
        self::assertFalse($this->face('late')->isVerified());
        self::assertFalse($this->face('elsewhere')->isVerified());
        self::assertNotNull($this->find(Person::class, (int) $anna->getId()), 'benannte Person bleibt immer');
    }

    public function testEachConfirmedFaceGoesToTheClosestNewFaceOnly(): void
    {
        // Refinement mit 1 fps: mehrere neue Frames innerhalb der Toleranz
        $video = VideoFactory::createOne(['analysisFps' => 0.2]);
        $anna = PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        $this->oldFace($video, $anna, timestamp: 10, left: 0.2, verified: true);

        $this->reset($video);
        $this->newFace($video, 'f9', 'before', timestamp: 9, left: 0.2);
        $this->newFace($video, 'f10', 'exact', timestamp: 10, left: 0.22);
        $this->newFace($video, 'f11', 'after', timestamp: 11, left: 0.2);

        self::assertSame(1, $this->carryover()->apply($this->reload($video)));
        self::assertFace('exact', (int) $anna->getId(), verified: true);
        self::assertFalse($this->face('before')->isVerified());
        self::assertFalse($this->face('after')->isVerified());
    }

    public function testFaceOfTheSameFrameIsMovedAwayFromTheCarriedPerson(): void
    {
        $video = VideoFactory::createOne(['analysisFps' => 0.2]);
        $anna = PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        $this->oldFace($video, $anna, timestamp: 10, left: 0.2, verified: true);

        $this->reset($video);
        // Embedding-Abgleich hielt das zweite Gesicht im Frame für Anna, das bestätigte nicht
        $this->newFace($video, 'f10', 'anna-pos', timestamp: 10, left: 0.2);
        $this->newFace($video, 'f10', 'neighbour', timestamp: 10, left: 0.6, person: $anna);

        $this->carryover()->apply($this->reload($video));

        self::assertFace('anna-pos', (int) $anna->getId(), verified: true);
        self::assertNotSame($anna->getId(), $this->face('neighbour')->getPerson()?->getId());
    }

    public function testConsolidationKeepsTheCarriedConfirmation(): void
    {
        $video = VideoFactory::createOne(['analysisFps' => 0.2]);
        $anna = PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        $this->oldFace($video, $anna, timestamp: 10, left: 0.2, verified: true);

        $this->reset($video);
        $this->newFace($video, 'f10', 'anna-new', timestamp: 10, left: 0.2);
        $this->carryover()->apply($this->reload($video));
        self::getContainer()->get(VideoFaceConsolidator::class)->consolidate($this->reload($video));

        self::assertFace('anna-new', (int) $anna->getId(), verified: true);
    }

    private function oldFace(Video $video, Person $person, int $timestamp, float $left, bool $verified): void
    {
        VideoFaceFactory::createOne([
            'video' => $video,
            'person' => $person,
            'timestamp' => $timestamp,
            'isVerified' => $verified,
            'faceImage' => FileFactory::new(),
            'boundingBox' => self::box($left),
        ]);
    }

    private function newFace(
        Video $video,
        string $frame,
        string $label,
        int $timestamp,
        float $left,
        ?Person $person = null,
    ): void {
        $this->frames[$frame] ??= FileFactory::createOne();
        VideoFaceFactory::createOne([
            'video' => $video,
            'person' => $person ?? PersonFactory::new(['name' => 'unknown_' . $label]),
            'faceLabel' => $label,
            'timestamp' => $timestamp,
            'faceImage' => $this->frames[$frame],
            'boundingBox' => self::box($left),
        ]);
    }

    /**
     * @return array<string, float>
     */
    private static function box(float $left): array
    {
        return ['Left' => $left, 'Top' => 0.3, 'Width' => 0.1, 'Height' => 0.15];
    }

    private function reset(Video $video): void
    {
        self::getContainer()->get(VideoAnalysisReset::class)->reset($this->reload($video));
        self::assertSame(0, self::getContainer()
            ->get(VideoFaceRepository::class)
            ->count(['medium' => $video->getId()]));
    }

    private function carryover(): VerifiedFaceCarryover
    {
        return self::getContainer()->get(VerifiedFaceCarryover::class);
    }

    private function reload(Video $video): Video
    {
        $reloaded = $this->find(Video::class, (int) $video->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function find(string $class, int $id): ?object
    {
        return self::getContainer()->get(EntityManagerInterface::class)->find($class, $id);
    }

    private function face(string $label): VideoFace
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $face = self::getContainer()->get(VideoFaceRepository::class)->findOneBy(['faceLabel' => $label]);
        self::assertInstanceOf(VideoFace::class, $face);

        return $face;
    }

    private function assertFace(string $label, int $personId, bool $verified): void
    {
        $face = $this->face($label);
        self::assertSame($personId, $face->getPerson()?->getId(), $label);
        self::assertSame($verified, $face->isVerified(), $label);
    }
}
