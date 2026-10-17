<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Person;
use App\Entity\VideoFace;
use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class MassFaceResolveComponent
{
    use DefaultActionTrait;

    private const BATCH_SIZE = 10;
    private const DUMP_PERSON_NAME = 'Unbekannt';

    #[LiveProp]
    public int $personId;

    /** @var int[] */
    #[LiveProp]
    public array $selectedForRemoval = [];

    public function __construct(
        private PersonRepository $personRepository,
        private EntityManagerInterface $entityManager,
    ) {}

    public function getPerson(): ?Person
    {
        return $this->personRepository->find($this->personId);
    }

    /**
     * Aktueller Batch (die nächsten unverifizierten Gesichter).
     *
     * @return VideoFace[]
     */
    public function getUnverifiedFaces(): array
    {
        return array_slice($this->allUnverifiedFaces(), 0, self::BATCH_SIZE);
    }

    public function getUnverifiedCount(): int
    {
        return count($this->allUnverifiedFaces());
    }

    public function getTotalCount(): int
    {
        $person = $this->getPerson();

        return $person ? $person->getVideoFaces()->count() : 0;
    }

    #[LiveAction]
    public function toggleSelection(#[LiveArg] int $faceId): void
    {
        if (in_array($faceId, $this->selectedForRemoval, true)) {
            $this->selectedForRemoval = array_values(array_diff($this->selectedForRemoval, [$faceId]));
        } else {
            $this->selectedForRemoval[] = $faceId;
        }
    }

    #[LiveAction]
    public function selectAll(): void
    {
        $this->selectedForRemoval = array_values(array_filter(array_map(
            static fn(VideoFace $face) => $face->getId(),
            $this->getUnverifiedFaces(),
        )));
    }

    #[LiveAction]
    public function clearSelection(): void
    {
        $this->selectedForRemoval = [];
    }

    #[LiveAction]
    public function submit(): void
    {
        $person = $this->getPerson();
        if (!$person) {
            return;
        }

        $batch = $this->getUnverifiedFaces();
        $selected = array_map('intval', $this->selectedForRemoval);

        $dumpPerson = null;
        foreach ($batch as $face) {
            $face->setIsVerified(true);

            if (in_array($face->getId(), $selected, true)) {
                $dumpPerson ??= $this->getOrCreateDumpPerson();
                $face->setPerson($dumpPerson);
            }
        }

        $this->selectedForRemoval = [];
        $this->entityManager->flush();
    }

    // ───────── intern ─────────

    /**
     * @return VideoFace[]
     */
    private function allUnverifiedFaces(): array
    {
        $person = $this->getPerson();
        if (!$person) {
            return [];
        }

        $faces = $person
            ->getVideoFaces()
            ->filter(static fn(VideoFace $face) => !$face->isVerified())
            ->toArray();
        usort($faces, static fn(VideoFace $a, VideoFace $b) => $a->getId() <=> $b->getId());

        return $faces;
    }

    /**
     * Dedizierte Sammelperson "Unbekannt" (nicht irgendeine Person mit Status UNKNOWN).
     */
    private function getOrCreateDumpPerson(): Person
    {
        $person = $this->personRepository->findOneBy([
            'status' => PersonStatus::UNKNOWN,
            'name' => self::DUMP_PERSON_NAME,
        ]);

        if (!$person) {
            $person = new Person();
            $person->setName(self::DUMP_PERSON_NAME);
            $person->setStatus(PersonStatus::UNKNOWN);
            $this->entityManager->persist($person);
            $this->entityManager->flush();
        }

        return $person;
    }
}
