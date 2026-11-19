<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Person;
use App\Entity\VideoFace;
use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class TinderResolveComponent
{
    use DefaultActionTrait;

    private const MAX_SHOWS = 10;
    private const MAX_THUMBS = 8;

    #[LiveProp]
    public ?int $currentPersonId = null;

    #[LiveProp]
    public ?int $activeFaceId = null;

    /** @var list<string> PersonStatus-Werte */
    #[LiveProp(writable: true, onUpdated: 'onStatusesUpdated')]
    public array $statuses = [];

    /** @var array<int, VideoFace[]> */
    private array $rankedCache = [];

    public function __construct(
        private PersonRepository $personRepository,
        private EntityManagerInterface $entityManager,
    ) {
        $this->statuses = [PersonStatus::NEW->value, PersonStatus::WASTED->value];
    }

    /**
     * @param list<string>|null $statuses
     */
    public function mount(?array $statuses = []): void
    {
        if (!empty($statuses)) {
            $this->statuses = $statuses;
        }
        $this->loadNext();
    }

    public function onStatusesUpdated(): void
    {
        $this->loadNext();
    }

    public function getPerson(): ?Person
    {
        return $this->currentPersonId ? $this->personRepository->find($this->currentPersonId) : null;
    }

    /**
     * Gesichter für die Miniaturen: Profilbild zuerst, dann nach Boxgröße (größte zuerst).
     *
     * @return VideoFace[]
     */
    public function getFaces(): array
    {
        $person = $this->getPerson();

        return $person ? array_slice($this->rankFaces($person), 0, self::MAX_THUMBS) : [];
    }

    public function getActiveFace(): ?VideoFace
    {
        $faces = $this->getFaces();
        if (empty($faces)) {
            return null;
        }

        if ($this->activeFaceId) {
            foreach ($faces as $face) {
                if ($face->getId() === $this->activeFaceId) {
                    return $face;
                }
            }
        }

        return $faces[0];
    }

    /**
     * @return array{prev: ?int, next: ?int}
     */
    public function getAdjacentFaceIds(): array
    {
        $none = ['prev' => null, 'next' => null];

        $faces = $this->getFaces();
        $active = $this->getActiveFace();
        $count = count($faces);
        if ($count < 2 || !$active) {
            return $none;
        }

        $ids = array_values(array_map(static fn(VideoFace $f) => $f->getId(), $faces));
        $index = array_search($active->getId(), $ids, true);
        if ($index === false) {
            $index = 0;
        }

        return [
            'prev' => $ids[($index - 1 + $count) % $count],
            'next' => $ids[($index + 1) % $count],
        ];
    }

    #[LiveAction]
    public function selectFace(#[LiveArg('faceId')] int $id): void
    {
        $this->activeFaceId = $id;
    }

    #[LiveAction]
    public function markAsUnknown(): void
    {
        $person = $this->getPerson();
        if ($person) {
            $person->setStatus(PersonStatus::UNKNOWN);
            $this->entityManager->flush();
        }

        $this->loadNext();
    }

    #[LiveAction]
    public function skip(): void
    {
        $person = $this->getPerson();
        if ($person) {
            $person->setShowCount($person->getShowCount() + 1);
            if ($person->getShowCount() >= self::MAX_SHOWS) {
                $person->setStatus(PersonStatus::UNKNOWN);
            }
            $this->entityManager->flush();
        }

        $this->loadNext();
    }

    #[LiveAction]
    public function assignToDetectedPerson(#[LiveArg] int $personId, #[LiveArg] int $detectedPersonId): void
    {
        $person = $this->personRepository->find($personId);
        $detectedPerson = $this->personRepository->find($detectedPersonId);

        if ($person && $detectedPerson && $person !== $detectedPerson) {
            foreach ($person->getVideoFaces() as $face) {
                $face->setPerson($detectedPerson);
                $face->setIsVerified(true);
            }
            $person->setStatus(PersonStatus::MERGED);
            $person->setMergedInto($detectedPerson);
            $person->setIdentified(true);
            $this->entityManager->flush();
        }

        $this->loadNext();
    }

    #[LiveAction]
    public function reload(): void
    {
        $this->loadNext();
    }

    public function getRemainingCount(): int
    {
        $values = $this->statusValues();
        if (empty($values)) {
            return 0;
        }

        return (int) $this
            ->createCandidateQueryBuilder($values)
            ->select('COUNT(DISTINCT p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    // ───────── intern ─────────

    private function loadNext(): void
    {
        $this->activeFaceId = null;
        $this->currentPersonId = null;

        $values = $this->statusValues();
        if (empty($values)) {
            return;
        }

        $result = $this
            ->createCandidateQueryBuilder($values)
            ->select('p.id AS id')
            ->addSelect('p.showCount AS HIDDEN show_order')
            ->addSelect('COUNT(f.id) AS HIDDEN face_count')
            ->groupBy('p.id')
            ->orderBy('show_order', \SortDirection::Ascending) // selten gezeigte zuerst
            ->addOrderBy('face_count', \SortDirection::Descending) // dann Personen mit vielen Sichtungen
            ->addOrderBy('p.id', \SortDirection::Ascending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $this->currentPersonId = $result ? (int) $result['id'] : null;
    }

    /**
     * @param string[] $statusValues
     */
    private function createCandidateQueryBuilder(array $statusValues): QueryBuilder
    {
        // inner join: Personen ohne Gesichter sind nie sinnvoll anzeigbar
        return $this->personRepository
            ->createQueryBuilder('p')
            ->join('p.videoFaces', 'f')
            ->where('p.status IN (:statuses)')
            ->andWhere('p.showCount < :maxShows')
            ->setParameter('statuses', $statusValues)
            ->setParameter('maxShows', self::MAX_SHOWS);
    }

    /**
     * @return string[]
     */
    private function statusValues(): array
    {
        $values = [];
        foreach ($this->statuses as $status) {
            $enum = PersonStatus::tryFrom($status);
            if ($enum !== null) {
                $values[] = $enum->value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @return VideoFace[]
     */
    private function rankFaces(Person $person): array
    {
        $id = (int) $person->getId();
        if (isset($this->rankedCache[$id])) {
            return $this->rankedCache[$id];
        }

        $faces = $person->getVideoFaces()->toArray();
        $profile = $person->getProfileFace();

        usort($faces, function (VideoFace $a, VideoFace $b) use ($profile) {
            if ($profile) {
                if ($a === $profile && $b !== $profile) {
                    return -1;
                }
                if ($b === $profile && $a !== $profile) {
                    return 1;
                }
            }

            return $this->faceArea($b) <=> $this->faceArea($a);
        });

        return $this->rankedCache[$id] = $faces;
    }

    private function faceArea(VideoFace $face): float
    {
        $box = $face->getBoundingBox();
        if (!is_array($box)) {
            return 0.0;
        }

        return (float) ($box['Width'] ?? 0) * (float) ($box['Height'] ?? 0);
    }
}
