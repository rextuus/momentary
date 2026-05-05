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
}