<?php

declare(strict_types=1);

namespace App\Tests\Integration\Face;

use App\Entity\Person;
use App\Enum\PersonStatus;
use App\Service\Face\OrphanedPersonCleaner;
use App\Service\Video\Analyze\SceneDetector;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrphanedPersonCleanerTest extends KernelTestCase
{
    public function testRemovesOnlyAutomaticPersonsWithoutFaces(): void
    {
        $emptyNew = PersonFactory::createOne(['status' => PersonStatus::NEW]);
        $emptyIdentified = PersonFactory::createOne(['name' => 'Oma', 'status' => PersonStatus::IDENTIFIED]);
        $withFace = PersonFactory::createOne(['status' => PersonStatus::NEW]);
        VideoFaceFactory::createOne(['person' => $withFace]);
        $mergedAway = PersonFactory::createOne(['status' => PersonStatus::MERGED, 'mergedInto' => $emptyNew]);

        $removed = self::getContainer()->get(OrphanedPersonCleaner::class)->removeOrphans();

        self::assertSame(1, $removed);
        self::assertSame(
            [$emptyIdentified->getId(), $withFace->getId(), $mergedAway->getId()],
            $this->remainingPersonIds(),
            'bearbeitete Personen, Personen mit Gesicht und Verweise darauf bleiben',
        );
    }

    public function testReprocessingAVideoRemovesPersonsThatOnlyAppearedThere(): void
    {
        $video = VideoFactory::createOne();
        $otherVideo = VideoFactory::createOne();
        $onlyHere = PersonFactory::createOne(['status' => PersonStatus::NEW]);
        $alsoElsewhere = PersonFactory::createOne(['status' => PersonStatus::NEW]);
        VideoFaceFactory::createOne(['video' => $video, 'person' => $onlyHere]);
        VideoFaceFactory::createOne(['video' => $video, 'person' => $alsoElsewhere]);
        VideoFaceFactory::createOne(['video' => $otherVideo, 'person' => $alsoElsewhere]);
        $unrelatedEmpty = PersonFactory::createOne(['status' => PersonStatus::NEW]);

        self::getContainer()->get(SceneDetector::class)->clearOldScenes($video);

        self::assertSame(
            [$alsoElsewhere->getId(), $unrelatedEmpty->getId()],
            $this->remainingPersonIds(),
            'nur Personen dieses Videos werden geprüft',
        );
    }

    /**
     * @return list<int|null>
     */
    private function remainingPersonIds(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return array_map(
            static fn(Person $person): ?int => $person->getId(),
            $entityManager->getRepository(Person::class)->findBy([], ['id' => 'ASC']),
        );
    }
}
