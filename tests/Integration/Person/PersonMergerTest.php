<?php

declare(strict_types=1);

namespace App\Tests\Integration\Person;

use App\Entity\Person;
use App\Entity\VideoFace;
use App\Enum\PersonStatus;
use App\Service\Person\DuplicatePersonFinder;
use App\Service\Person\PersonMerger;
use App\Service\Person\PersonNameHints;
use App\Service\Photo\PhotoSearchIndex;
use App\Service\VideoSearchIndex;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\TagCategoryFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoSceneFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PersonMergerTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->set(VideoSearchIndex::class, $this->createStub(VideoSearchIndex::class));
        self::getContainer()->set(PhotoSearchIndex::class, $this->createStub(PhotoSearchIndex::class));
    }

    public function testMergeMovesFacesWithoutDeletingThem(): void
    {
        $source = PersonFactory::createOne(['status' => PersonStatus::NEW]);
        $target = PersonFactory::createOne(['name' => 'Oma', 'status' => PersonStatus::IDENTIFIED]);
        VideoFaceFactory::createMany(3, ['person' => $source]);
        $own = VideoFaceFactory::createOne(['person' => $target]);

        $this->merger()->merge($this->reload($source), $this->reload($target));
        $em = $this->em();
        $em->clear();

        self::assertSame(4, $em->getRepository(VideoFace::class)->count(['person' => $target->getId()]));
        self::assertSame(4, $em->getRepository(VideoFace::class)->count([]), 'kein Gesicht gelöscht (orphanRemoval)');
        $merged = $em->find(Person::class, $source->getId());
        self::assertSame(PersonStatus::MERGED, $merged?->getStatus());
        self::assertSame($target->getId(), $merged->getMergedInto()?->getId());
        self::assertSame(
            3,
            $em->getRepository(VideoFace::class)->count(['isVerified' => true]),
            'umgehängte bestätigt',
        );
        self::assertFalse($em->find(VideoFace::class, $own->getId())?->isVerified(), 'eigene bleiben automatisch');
    }

    public function testNamingWithAnExistingNameMergesIntoThatPerson(): void
    {
        $oma = PersonFactory::createOne(['name' => 'Oma', 'status' => PersonStatus::IDENTIFIED]);
        $unknown = PersonFactory::createOne(['status' => PersonStatus::NEW]);
        VideoFaceFactory::createOne(['person' => $unknown]);

        $result = $this->merger()->name($this->reload($unknown), ' oma ');

        self::assertSame($oma->getId(), $result->getId());
        self::assertSame(PersonStatus::MERGED, $this->reload($unknown)->getStatus());
    }

    public function testNamingWithANewNameIdentifiesThePerson(): void
    {
        $unknown = PersonFactory::createOne(['status' => PersonStatus::NEW]);
        $face = VideoFaceFactory::createOne(['person' => $unknown]);

        $result = $this->merger()->name($this->reload($unknown), 'Joscha');

        self::assertSame('Joscha', $result->getName());
        self::assertSame(PersonStatus::IDENTIFIED, $result->getStatus());
        $this->em()->clear();
        self::assertTrue($this->em()->find(VideoFace::class, $face->getId())?->isVerified(), 'Benennen bestätigt');
    }

    public function testFinderSuggestsSimilarPersonsKeepingTheNamedOne(): void
    {
        $named = PersonFactory::createOne(['name' => 'Oma', 'status' => PersonStatus::IDENTIFIED]);
        $unnamed = PersonFactory::createOne(['status' => PersonStatus::NEW]);
        VideoFaceFactory::createOne(['person' => $named, 'faceLabel' => 'face-a']);
        VideoFaceFactory::createOne([
            'person' => $unnamed,
            'faceLabel' => 'face-b',
            'matchCandidates' => ['face-a' => 85.0, 'face-x' => 99.0],
        ]);

        $suggestions = $this->finder()->find();

        self::assertCount(1, $suggestions);
        self::assertSame($named->getId(), $suggestions[0]->keep->getId());
        self::assertSame($unnamed->getId(), $suggestions[0]->merge->getId());
        self::assertSame(85.0, $suggestions[0]->similarity);
        self::assertSame('wahrscheinlich', $suggestions[0]->getConfidence());
    }

    public function testFinderSkipsWeakPairsNamedPairsAndPersonsInTheSameImage(): void
    {
        $a = PersonFactory::createOne(['status' => PersonStatus::NEW]);
        $weak = PersonFactory::createOne(['status' => PersonStatus::NEW]);
        $sameFrame = PersonFactory::createOne(['status' => PersonStatus::NEW]);
        $named1 = PersonFactory::createOne(['name' => 'Oma', 'status' => PersonStatus::IDENTIFIED]);
        $named2 = PersonFactory::createOne(['name' => 'Opa', 'status' => PersonStatus::IDENTIFIED]);
        $frame = FileFactory::createOne();
        VideoFaceFactory::createOne(['person' => $a, 'faceLabel' => 'a', 'faceImage' => $frame]);
        VideoFaceFactory::createOne(['person' => $weak, 'faceLabel' => 'w', 'matchCandidates' => ['a' => 50.0]]);
        VideoFaceFactory::createOne([
            'person' => $sameFrame,
            'faceLabel' => 's',
            'faceImage' => $frame,
            'matchCandidates' => ['a' => 95.0],
        ]);
        VideoFaceFactory::createOne(['person' => $named1, 'faceLabel' => 'n1']);
        VideoFaceFactory::createOne(['person' => $named2, 'faceLabel' => 'n2', 'matchCandidates' => ['n1' => 95.0]]);

        self::assertSame([], $this->finder()->find());
    }

    public function testDismissedPairsAreNotSuggestedAgain(): void
    {
        $a = PersonFactory::createOne(['status' => PersonStatus::NEW]);
        $b = PersonFactory::createOne(['status' => PersonStatus::NEW]);
        VideoFaceFactory::createOne(['person' => $a, 'faceLabel' => 'a']);
        VideoFaceFactory::createOne(['person' => $b, 'faceLabel' => 'b', 'matchCandidates' => ['a' => 92.0]]);
        self::assertCount(1, $this->finder()->find());

        $this->finder()->dismiss($this->reload($b), $this->reload($a));
        $this->finder()->dismiss($this->reload($a), $this->reload($b));

        self::assertSame([], $this->finder()->find());
    }

    public function testNameHintsComeFromScenesShowingThePerson(): void
    {
        $category = TagCategoryFactory::createOne(['name' => PersonNameHints::CATEGORY]);
        $joscha = TagFactory::createOne(['name' => 'Joscha', 'category' => $category]);
        $mario = TagFactory::createOne(['name' => 'Mario', 'category' => $category]);
        $topic = TagFactory::createOne(['name' => 'Pool']);
        $person = PersonFactory::createOne(['status' => PersonStatus::NEW]);
        foreach ([[$joscha, $topic], [$joscha, $mario]] as $tags) {
            $scene = VideoSceneFactory::createOne();
            foreach ($tags as $tag) {
                $scene->addSceneTag($tag);
            }
            $this->em()->flush();
            VideoFaceFactory::createOne(['person' => $person, 'video' => $scene->getVideo(), 'videoScene' => $scene]);
        }

        $hints = self::getContainer()->get(PersonNameHints::class)->forPerson($this->reload($person));

        self::assertSame([['name' => 'Joscha', 'scenes' => 2], ['name' => 'Mario', 'scenes' => 1]], $hints);
    }

    private function reload(Person $person): Person
    {
        $reloaded = $this->em()->find(Person::class, $person->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function merger(): PersonMerger
    {
        return self::getContainer()->get(PersonMerger::class);
    }

    private function finder(): DuplicatePersonFinder
    {
        return self::getContainer()->get(DuplicatePersonFinder::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
