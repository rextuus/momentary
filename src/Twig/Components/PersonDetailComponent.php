<?php

namespace App\Twig\Components;

use App\Entity\Person;
use App\Repository\VideoFaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class PersonDetailComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public Person $person;

    public function __construct(
        private VideoFaceRepository $videoFaceRepository,
        private EntityManagerInterface $entityManager
    ) {}

    #[LiveAction]
    public function setAsProfile(#[LiveArg] int $faceId): void
    {
        $face = $this->videoFaceRepository->find($faceId);

        // Sicherheitscheck: Gehört das Gesicht zur Person?
        if ($face && $face->getPerson() === $this->person) {
            $this->person->setProfileFace($face);
            $this->entityManager->flush();

            $this->addFlash('success', 'Profilbild wurde aktualisiert.');
        }
    }

    #[LiveAction]
    public function splitToNewPerson(#[LiveArg] int $faceId): void
    {
        $face = $this->videoFaceRepository->find($faceId);

        // Sicherheitscheck: Existiert das Gesicht und gehört es aktuell zu dieser Person?
        if (!$face || $face->getPerson() !== $this->person) {
            return;
        }

        // 1. Neue unbekannte Person anlegen
        $newPerson = new Person();
        $uniqueId = substr(md5((string)hrtime(true)), 0, 8);
        $newPerson->setName('unknown_' . $uniqueId);
        $newPerson->setIdentified(false);
        $newPerson->setStatus(\App\Enum\PersonStatus::NEW);

        $this->entityManager->persist($newPerson);

        // 2. Das Gesicht der neuen Person zuweisen
        $face->setPerson($newPerson);

        // 3. Falls das Gesicht das Profilbild der alten Person war, dieses leeren
        if ($this->person->getProfileFace() === $face) {
            $this->person->setProfileFace(null);
        }

        $this->entityManager->flush();

        $this->addFlash('success', 'Gesicht wurde erfolgreich in eine neue Person ausgelagert.');
    }
}