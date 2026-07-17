<?php

namespace App\Twig\Components;

use App\Entity\Person;
use App\Entity\VideoFace;
use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use App\Repository\VideoFaceRepository;
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

    #[LiveProp]
    public int $personId;

    #[LiveProp]
    public array $selectedForRemoval = [];

    public function __construct(
        private PersonRepository $personRepository,
        private VideoFaceRepository $videoFaceRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    public function getPerson(): ?Person
    {
        return $this->personRepository->find($this->personId);
    }

    public function getUnverifiedFaces(): array
    {
        $person = $this->getPerson();
        if (!$person) {
            return [];
        }
        
        $faces = $person->getVideoFaces()->filter(fn($face) => !$face->isVerified())->toArray();
        usort($faces, fn(VideoFace $a, VideoFace $b) => $a->getId() <=> $b->getId());
        
        return array_slice($faces, 0, 10);
    }

    #[LiveAction]
    public function toggleSelection(#[LiveArg] int $faceId): void
    {
        if (in_array($faceId, $this->selectedForRemoval)) {
            $this->selectedForRemoval = array_values(array_diff($this->selectedForRemoval, [$faceId]));
        } else {
            $this->selectedForRemoval[] = $faceId;
        }
    }

    #[LiveAction]
    public function submit(): void
    {
        $person = $this->getPerson();
        if (!$person) {
            return;
        }

        $unknownPerson = $this->personRepository->findOneBy(['status' => PersonStatus::UNKNOWN]);
        if (!$unknownPerson) {
            $unknownPerson = new \App\Entity\Person();
            $unknownPerson->setName('Unbekannt');
            $unknownPerson->setStatus(PersonStatus::UNKNOWN);
            $this->entityManager->persist($unknownPerson);
            $this->entityManager->flush();
        }

        foreach ($this->getUnverifiedFaces() as $face) {
            $face->setIsVerified(true);
            if (in_array($face->getId(), $this->selectedForRemoval)) {
                $face->setPerson($unknownPerson);
            }
        }
        $this->selectedForRemoval = [];
        $this->entityManager->flush();
    }
}
