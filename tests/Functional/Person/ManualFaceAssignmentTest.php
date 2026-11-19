<?php

declare(strict_types=1);

namespace App\Tests\Functional\Person;

use App\Entity\User;
use App\Entity\VideoFace;
use App\Enum\PersonStatus;
use App\Service\Photo\PhotoSearchIndex;
use App\Service\VideoSearchIndex;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFaceFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Jede Zuordnung von Hand markiert die Gesichter als bestätigt; nur bestätigte überstehen das Neuverarbeiten
 * (VerifiedFaceCarryover). Benennen und Zusammenführen siehe auch PersonMergerTest.
 */
final class ManualFaceAssignmentTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private KernelBrowser $client;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set(VideoSearchIndex::class, $this->createStub(VideoSearchIndex::class));
        static::getContainer()->set(PhotoSearchIndex::class, $this->createStub(PhotoSearchIndex::class));
        $this->admin = UserFactory::new()->admin()->create();
    }

    public function testReassigningASingleFaceConfirmsIt(): void
    {
        $split = PersonFactory::createOne(['name' => 'split_1', 'status' => PersonStatus::TO_REASSIGN]);
        $anna = PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        $face = VideoFaceFactory::createOne(['person' => $split]);
        $faceId = (int) $face->getId();

        $this
            ->createLiveComponent('FaceReassignComponent', ['activeFaceId' => $faceId], $this->client)
            ->actingAs($this->admin)
            ->call('assignToPerson', ['personId' => $anna->getId()]);

        self::assertConfirmed($faceId, (int) $anna->getId());
    }

    public function testFaceMarkedAsStrangerIsConfirmed(): void
    {
        $split = PersonFactory::createOne(['name' => 'split_2', 'status' => PersonStatus::TO_REASSIGN]);
        $face = VideoFaceFactory::createOne(['person' => $split]);
        $faceId = (int) $face->getId();

        $this
            ->createLiveComponent('FaceReassignComponent', ['activeFaceId' => $faceId], $this->client)
            ->actingAs($this->admin)
            ->call('markAsUnknown');

        self::assertTrue($this->face($faceId)->isVerified());
    }

    public function testSwitchingThePersonOnTheFaceConfirmsIt(): void
    {
        $auto = PersonFactory::createOne(['name' => 'unknown_a', 'status' => PersonStatus::NEW]);
        $anna = PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        $face = VideoFaceFactory::createOne(['person' => $auto]);
        $faceId = (int) $face->getId();

        $this
            ->createLiveComponent('VideoFaceComponent', ['videoFace' => $face], $this->client)
            ->actingAs($this->admin)
            ->submitForm(['video_face_switch' => ['target' => (string) $anna->getId()]], 'submit');

        self::assertConfirmed($faceId, (int) $anna->getId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function resolveViews(): iterable
    {
        yield 'Schnell zuordnen' => ['QuickResolveComponent'];
        yield 'Tinder' => ['TinderResolveComponent'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('resolveViews')]
    public function testAcceptingTheSuggestionConfirmsAllFaces(string $component): void
    {
        $auto = PersonFactory::createOne(['name' => 'unknown_b', 'status' => PersonStatus::NEW]);
        $anna = PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        $faces = VideoFaceFactory::createMany(2, ['person' => $auto, 'detection' => $anna]);

        $this
            ->createLiveComponent($component, [], $this->client)
            ->actingAs($this->admin)
            ->call('assignToDetectedPerson', ['personId' => $auto->getId(), 'detectedPersonId' => $anna->getId()]);

        foreach ($faces as $face) {
            self::assertConfirmed((int) $face->getId(), (int) $anna->getId());
        }
    }

    public function testChoosingASuggestedPersonInTheResolverConfirmsTheFaces(): void
    {
        $auto = PersonFactory::createOne(['name' => 'unknown_c', 'status' => PersonStatus::NEW]);
        $anna = PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        $face = VideoFaceFactory::createOne(['person' => $auto]);

        $this
            ->createLiveComponent('PersonResolverComponent', ['currentPersonId' => $auto->getId()], $this->client)
            ->actingAs($this->admin)
            ->call('selectSuggestedPerson', ['id' => $anna->getId()]);

        self::assertConfirmed((int) $face->getId(), (int) $anna->getId());
    }

    public function testRenamingAPersonConfirmsItsFaces(): void
    {
        $person = PersonFactory::createOne(['name' => 'unknown_d', 'status' => PersonStatus::NEW]);
        $face = VideoFaceFactory::createOne(['person' => $person]);

        $this
            ->createLiveComponent('PersonName', ['person' => $person], $this->client)
            ->actingAs($this->admin)
            ->submitForm(['person_name' => ['name' => 'Joscha']], 'submit');

        self::assertConfirmed((int) $face->getId(), (int) $person->getId());
    }

    public function testEditingOtherDetailsConfirmsNothingButChangingTheNameDoes(): void
    {
        $person = PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        $face = VideoFaceFactory::createOne(['person' => $person]);
        $faceId = (int) $face->getId();

        $component = $this->createLiveComponent(
            'PersonDetailComponent',
            ['person' => $person],
            $this->client,
        )->actingAs($this->admin);
        $component->set('person.relation', 'Tante')->call('save');
        self::assertFalse($this->face($faceId)->isVerified(), 'nur Angaben zur Person geändert');

        $component->set('person.name', 'Anna Müller')->call('save');
        self::assertTrue($this->face($faceId)->isVerified());
    }

    private function face(int $id): VideoFace
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $face = $entityManager->find(VideoFace::class, $id);
        self::assertNotNull($face);

        return $face;
    }

    private function assertConfirmed(int $faceId, int $personId): void
    {
        $face = $this->face($faceId);
        self::assertSame($personId, $face->getPerson()?->getId());
        self::assertTrue($face->isVerified());
    }
}
