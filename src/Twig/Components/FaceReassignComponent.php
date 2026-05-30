<?php

namespace App\Twig\Components;

use App\Entity\Person;
use App\Entity\VideoFace;
use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use App\Repository\VideoFaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class FaceReassignComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public ?int $activeFaceId = null;

    #[LiveProp(writable: true)]
    public string $search = '';

    public function mount(?int $activeFaceId = null): void
    {
        if ($activeFaceId) {
            $this->activeFaceId = $activeFaceId;
        }
    }

    public function __construct(
        private VideoFaceRepository $videoFaceRepository,
        private PersonRepository $personRepository,
        private EntityManagerInterface $entityManager
    ) {}

    public function getFaces(): array
    {
        return $this->videoFaceRepository->createQueryBuilder('vf')
            ->join('vf.person', 'p')
            ->where('p.status = :status')
            ->setParameter('status', PersonStatus::TO_REASSIGN)
            ->orderBy('p.id', 'ASC')
            ->addOrderBy('vf.timestamp', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getActiveFace(): ?VideoFace
    {
        if ($this->activeFaceId) {
            return $this->videoFaceRepository->find($this->activeFaceId);
        }

        $faces = $this->getFaces();
        return $faces[0] ?? null;
    }

    #[LiveAction]
    public function selectFace(#[LiveArg] int $id): void
    {
        $this->activeFaceId = $id;
    }

    #[LiveAction]
    public function assignToNewPerson(): void
    {
        $face = $this->getActiveFace();
        if (!$face) return;

        $oldPerson = $face->getPerson();
        
        $newPerson = new Person();
        $newPerson->setName('Neu zugewiesen ' . uniqid());
        $newPerson->setStatus(PersonStatus::NEW);
        
        $this->entityManager->persist($newPerson);
        $face->setPerson($newPerson);
        
        $this->checkOldPerson($oldPerson);
        
        $this->entityManager->flush();
        $this->activeFaceId = null;
    }

    #[LiveAction]
    public function assignToPerson(#[LiveArg] int $personId): void
    {
        $face = $this->getActiveFace();
        $targetPerson = $this->personRepository->find($personId);
        
        if (!$face || !$targetPerson) return;

        $oldPerson = $face->getPerson();
        $face->setPerson($targetPerson);
        
        // Falls die Zielperson UNKNOWN oder WASTED war, aber nun aktiv zugewiesen wird, 
        // setzen wir sie auf IDENTIFIED (optional, je nach Workflow)
        if ($targetPerson->getStatus() === PersonStatus::UNKNOWN || $targetPerson->getStatus() === PersonStatus::WASTED) {
            $targetPerson->setStatus(PersonStatus::IDENTIFIED);
        }

        $this->checkOldPerson($oldPerson);
        
        $this->entityManager->flush();
        $this->activeFaceId = null;
    }

    #[LiveAction]
    public function markAsUnknown(): void
    {
        $face = $this->getActiveFace();
        if (!$face) return;

        $oldPerson = $face->getPerson();
        
        $newPerson = new Person();
        $newPerson->setName('Unbekannt ' . uniqid());
        $newPerson->setStatus(PersonStatus::UNKNOWN);
        
        $this->entityManager->persist($newPerson);
        $face->setPerson($newPerson);
        
        $this->checkOldPerson($oldPerson);
        
        $this->entityManager->flush();
        $this->activeFaceId = null;
    }

    private function checkOldPerson(?Person $person): void
    {
        if (!$person) return;

        // Wenn die Person keine Gesichter mehr hat, löschen wir sie
        $remainingFaces = $this->videoFaceRepository->count(['person' => $person]);
        if ($remainingFaces === 0) {
            $this->entityManager->remove($person);
        }
    }

    public function getSuggestions(): array
    {
        $qb = $this->personRepository->createQueryBuilder('p')
            ->leftJoin('p.videoFaces', 'vf')
            ->addSelect('COUNT(vf.id) as HIDDEN faceCount')
            ->where('p.status = :status')
            ->setParameter('status', PersonStatus::IDENTIFIED)
            ->groupBy('p.id');

        if ($this->search) {
            $qb->andWhere('p.name LIKE :search')
               ->setParameter('search', '%' . $this->search . '%');
            $limit = 20;
        } else {
            $limit = 12; // 4 Reihen à 3 Spalten mobil/desktop
        }

        return $qb->orderBy('faceCount', 'DESC')
            ->addOrderBy('p.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
    
    #[LiveAction]
    public function finishPerson(): void
    {
        $face = $this->getActiveFace();
        if (!$face) return;
        
        $person = $face->getPerson();
        if ($person) {
            // Alle restlichen Gesichter dieser Person als "Gemerged" oder "Identifiziert" lassen?
            // Der User will sie wahrscheinlich einfach aus der Liste haben.
            $person->setStatus(PersonStatus::IDENTIFIED);
            $this->entityManager->flush();
        }
        $this->activeFaceId = null;
    }
}
