<?php

namespace App\Twig\Components;

use App\Entity\Person;
use App\Enum\PersonStatus;
use App\Form\PersonResolverType;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class PersonResolverComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;

    #[LiveProp]
    public ?int $currentPersonId = null;

    #[LiveProp(writable: true)]
    public string $newName = '';

    #[LiveProp]
    public ?int $activeFaceId = null;

    public function __construct(
        private PersonRepository $personRepository,
        private EntityManagerInterface $entityManager
    ) {}

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(PersonResolverType::class);
    }

    public function mount(): void
    {
        $this->loadNextBestPerson();
    }

    public function getUnidentifiedPerson(): ?Person
    {
        return $this->currentPersonId ? $this->personRepository->find($this->currentPersonId) : null;
    }

    private function loadNextBestPerson(): void
    {
        $qb = $this->personRepository->createQueryBuilder('p')
            ->select('p.id')
            ->leftJoin('p.videoFaces', 'f')
            // Wir laden nur Personen, die noch den Status NEW haben
            ->where('p.status = :status')
            ->setParameter('status', PersonStatus::NEW);

        if ($this->currentPersonId) {
            $qb->andWhere('p.id != :cid')->setParameter('cid', $this->currentPersonId);
        }

        $qb->groupBy('p.id')
            ->orderBy('COUNT(f.id)', 'DESC')
            ->setMaxResults(1);

        $result = $qb->getQuery()->getOneOrNullResult();

        if ($result) {
            $this->currentPersonId = (int) $result['id'];
            $this->activeFaceId = null;
        } else {
            $this->currentPersonId = null;
        }
        $this->newName = '';
    }

    public function getActiveFace()
    {
        $person = $this->getUnidentifiedPerson();
        if (!$person) return null;

        $faces = $person->getVideoFaces();
        if ($faces->isEmpty()) return null;

        if ($this->activeFaceId) {
            foreach ($faces as $face) {
                if ($face->getId() === $this->activeFaceId) return $face;
            }
        }

        return $faces->first();
    }

    #[LiveAction]
    public function selectFace(#[LiveArg('faceId')] int $id): void
    {
        $this->activeFaceId = $id;
    }

    #[LiveAction]
    public function markAsUnknown(): void
    {
        $currentPerson = $this->getUnidentifiedPerson();
        if (!$currentPerson) return;

        // Dauerhaft aus dem Loop entfernen
        $currentPerson->setStatus(PersonStatus::UNKNOWN);

        $this->entityManager->flush();
        $this->resetForm();
        $this->loadNextBestPerson();
    }

    #[LiveAction]
    public function processIdentification(): void
    {
        $currentPerson = $this->getUnidentifiedPerson();
        if (!$currentPerson) return;

        $this->submitForm();
        $targetPerson = $this->getForm()->get('targetPerson')->getData();

        if ($targetPerson instanceof Person) {
            // MERGE: Bestehende Person ausgewählt
            foreach ($currentPerson->getVideoFaces() as $face) {
                $face->setPerson($targetPerson);
            }
            $currentPerson->setStatus(PersonStatus::IDENTIFIED);
            $currentPerson->setIdentified(true); // Kompatibilität
            $currentPerson->setName($currentPerson->getName() . ' (merged)');
        }
        elseif (!empty(trim($this->newName))) {
            // NEU: Name eingegeben
            $trimmedName = trim($this->newName);
            $existingPerson = $this->personRepository->findOneBy(['name' => $trimmedName]);

            if ($existingPerson) {
                $this->addFlash('error', sprintf('Die Person "%s" existiert bereits.', $trimmedName));
                return;
            }

            $currentPerson->setName($trimmedName);
            $currentPerson->setStatus(PersonStatus::IDENTIFIED);
            $currentPerson->setIdentified(true); // Kompatibilität
        } else {
            // Nichts ausgewählt oder eingegeben
            return;
        }

        $this->entityManager->flush();
        $this->resetForm();
        $this->loadNextBestPerson();
    }

    #[LiveAction]
    public function skip(): void
    {
        $this->resetForm();
        $this->loadNextBestPerson();
    }

    public function getRemainingCount(): int
    {
        // Zähle nur die Personen, die noch bearbeitet werden müssen
        return $this->personRepository->count(['status' => PersonStatus::NEW]);
    }
}