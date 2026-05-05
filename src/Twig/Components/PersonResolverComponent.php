<?php

namespace App\Twig\Components;

use App\Entity\Person;
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
            ->where('p.identified = :identified')
            ->setParameter('identified', false);

        if ($this->currentPersonId) {
            $qb->andWhere('p.id != :cid')->setParameter('cid', $this->currentPersonId);
        }

        $qb->groupBy('p.id')
            ->orderBy('COUNT(f.id)', 'DESC')
            ->setMaxResults(1);

        $result = $qb->getQuery()->getOneOrNullResult();

        if (!$result && $this->currentPersonId) {
            // behalte aktuelle
        } else {
            $this->currentPersonId = $result ? (int) $result['id'] : null;
            // WICHTIG: Setze das aktive Face auf null, damit das erste der neuen Person genommen wird
            $this->activeFaceId = null;
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
    public function processIdentification(): void
    {
        $currentPerson = $this->getUnidentifiedPerson();
        if (!$currentPerson) return;

        $this->submitForm();
        $targetPerson = $this->getForm()->get('targetPerson')->getData();

        if ($targetPerson instanceof Person) {
            foreach ($currentPerson->getVideoFaces() as $face) {
                $face->setPerson($targetPerson);
            }
            $currentPerson->setIdentified(true);
            $currentPerson->setName($currentPerson->getName() . ' (merged)');
        }
        elseif (!empty(trim($this->newName))) {
            $trimmedName = trim($this->newName);
            $existingPerson = $this->personRepository->findOneBy(['name' => $trimmedName]);
            if ($existingPerson) {
                $this->addFlash('error', sprintf('Die Person "%s" existiert bereits.', $trimmedName));
                return;
            }
            $currentPerson->setName($trimmedName);
            $currentPerson->setIdentified(true);
        } else {
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
        return $this->personRepository->count(['identified' => false]);
    }
}