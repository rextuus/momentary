<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Person;
use App\Entity\VideoFace;
use App\Enum\PersonStatus;
use App\Form\PersonResolverType;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
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

    private const MAX_SHOWS = 10;

    #[LiveProp]
    public ?int $currentPersonId = null;

    #[LiveProp(writable: true)]
    public string $newName = '';

    #[LiveProp(writable: true)]
    public string $newFullName = '';

    #[LiveProp(writable: true)]
    public ?int $newAge = null;

    #[LiveProp(writable: true)]
    public ?string $newGender = null;

    #[LiveProp(writable: true)]
    public ?string $newRelation = null;

    #[LiveProp(writable: true)]
    public ?string $newCharacteristics = null;

    #[LiveProp]
    public ?int $activeFaceId = null;

    #[LiveProp(writable: true, onUpdated: 'onShowWastedUpdated')]
    public bool $showWasted = false;

    #[LiveProp]
    public bool $fromQuickResolve = false;

    #[LiveProp]
    public bool $fromTinder = false;

    /** @var list<int> */
    #[LiveProp]
    public array $mergeIds = [];

    /**
     * IDs der in dieser Sitzung übersprungenen Personen.
     *
     * @var int[]
     */
    #[LiveProp]
    public array $skippedIds = [];

    public function __construct(
        private PersonRepository $personRepository,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @return FormInterface<array<string, mixed>|null>
     */
    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(PersonResolverType::class);
    }

    public function mount(
        ?int $currentPersonId = null,
        bool $fromQuickResolve = false,
        bool $fromTinder = false,
        ?string $mergeIds = null,
    ): void {
        $this->fromQuickResolve = $fromQuickResolve;
        $this->fromTinder = $fromTinder;
        if ($mergeIds) {
            $this->mergeIds = array_values(array_filter(array_map('intval', explode(',', $mergeIds))));
        }
        if ($currentPersonId) {
            $this->currentPersonId = $currentPersonId;
            // Ausgeblendete Person gezielt geladen -> Filter automatisch aktivieren
            $person = $this->personRepository->find($currentPersonId);
            if ($person && $person->getStatus() === PersonStatus::WASTED) {
                $this->showWasted = true;
            }
        } else {
            $this->loadNextBestPerson();
        }
    }

    public function onShowWastedUpdated(): void
    {
        if (!$this->getUnidentifiedPerson()) {
            $this->loadNextBestPerson();
        }
    }

    public function getUnidentifiedPerson(): ?Person
    {
        return $this->currentPersonId ? $this->personRepository->find($this->currentPersonId) : null;
    }

    private function createCandidateQueryBuilder(): QueryBuilder
    {
        $statuses = [PersonStatus::NEW->value];
        if ($this->showWasted) {
            $statuses[] = PersonStatus::WASTED->value;
        }

        $qb = $this->personRepository
            ->createQueryBuilder('p')
            ->where('p.status IN (:statuses)')
            ->setParameter('statuses', $statuses);

        if (!empty($this->skippedIds)) {
            $qb->andWhere('p.id NOT IN (:skipped)')->setParameter('skipped', array_values($this->skippedIds));
        }

        return $qb;
    }

    private function loadNextBestPerson(): void
    {
        $qb = $this
            ->createCandidateQueryBuilder()
            ->select('p.id')
            ->leftJoin('p.videoFaces', 'f')
            ->addSelect('COUNT(DISTINCT f.videoScene) AS HIDDEN scene_count')
            ->addSelect('COUNT(f.id) AS HIDDEN face_count');

        if ($this->currentPersonId) {
            $qb->andWhere('p.id != :cid')->setParameter('cid', $this->currentPersonId);
        }

        $qb
            ->groupBy('p.id')
            ->orderBy('scene_count', \SortDirection::Descending)
            ->addOrderBy('face_count', \SortDirection::Descending)
            ->addOrderBy('p.id', \SortDirection::Ascending)
            ->setMaxResults(1);

        $result = $qb->getQuery()->getOneOrNullResult();

        $this->currentPersonId = $result ? (int) $result['id'] : null;

        $this->activeFaceId = null;
        $this->newName = '';
        $this->newFullName = '';
        $this->newAge = null;
        $this->newGender = null;
        $this->newRelation = null;
        $this->newCharacteristics = null;
    }

    public function getActiveFace(): ?VideoFace
    {
        $person = $this->getUnidentifiedPerson();
        if (!$person) {
            return null;
        }

        $faces = $person->getVideoFaces();
        if ($faces->isEmpty()) {
            return null;
        }

        if ($this->activeFaceId) {
            foreach ($faces as $face) {
                if ($face->getId() === $this->activeFaceId) {
                    return $face;
                }
            }
        }

        return $faces->first();
    }

    /**
     * IDs des vorigen/nächsten Gesichts (zyklisch) für die Pfeil-Navigation.
     *
     * @return array{prev: ?int, next: ?int}
     */
    public function getAdjacentFaceIds(): array
    {
        $none = ['prev' => null, 'next' => null];

        $person = $this->getUnidentifiedPerson();
        $active = $this->getActiveFace();
        if (!$person || !$active) {
            return $none;
        }

        $ids = array_values(array_map(static fn($face) => $face->getId(), $person->getVideoFaces()->toArray()));
        $count = count($ids);
        if ($count < 2) {
            return $none;
        }

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
    public function markAsUnknown(): ?RedirectResponse
    {
        $currentPerson = $this->getUnidentifiedPerson();
        if (!$currentPerson) {
            return null;
        }

        // Dauerhaft aus dem Loop entfernen
        $currentPerson->setStatus(PersonStatus::UNKNOWN);

        $this->entityManager->flush();

        if ($this->fromQuickResolve) {
            $this->addFlash('success', 'Person als unbekannt markiert.');
            return $this->redirectToRoute('app_identity_quick_resolve');
        }

        if ($this->fromTinder) {
            $this->addFlash('success', 'Person als unbekannt markiert.');
            return $this->redirectToRoute('app_identity_tinder_resolve');
        }

        $this->resetForm();
        $this->loadNextBestPerson();

        return null;
    }

    #[LiveAction]
    public function processIdentification(): ?RedirectResponse
    {
        $currentPerson = $this->getUnidentifiedPerson();
        if (!$currentPerson) {
            return null;
        }

        $this->submitForm();
        $targetPerson = $this->getForm()->get('targetPerson')->getData();

        if ($targetPerson instanceof Person) {
            // MERGE: Bestehende Person ausgewählt
            $personsToMerge = array_merge([$currentPerson], $this->getMergePersons());
            foreach ($personsToMerge as $p) {
                foreach ($p->getVideoFaces() as $face) {
                    $face->setPerson($targetPerson);
                    $face->setIsVerified(true);
                }
                if ($p !== $targetPerson) {
                    $p->setStatus(PersonStatus::MERGED);
                    $p->setMergedInto($targetPerson);
                    $p->setIdentified(true);
                    if ($p->getName() === null || $p->getName() === '') {
                        $p->setName($targetPerson->getName() . ' (merged)');
                    }
                }
            }
        } elseif (!empty(trim($this->newName))) {
            // NEU: Name eingegeben
            $trimmedName = trim($this->newName);
            $existingPerson = $this->personRepository->findOneBy(['name' => $trimmedName]);

            if ($existingPerson) {
                $this->addFlash('error', sprintf('Die Person "%s" existiert bereits.', $trimmedName));
                return null;
            }

            $currentPerson->setName($trimmedName);
            $currentPerson->setFullName(trim($this->newFullName));
            $currentPerson->setAge($this->newAge);
            $currentPerson->setGender($this->newGender);
            $currentPerson->setRelation($this->newRelation);
            $currentPerson->setCharacteristics($this->newCharacteristics);
            $currentPerson->setStatus(PersonStatus::IDENTIFIED);
            $currentPerson->setIdentified(true); // Kompatibilität
            $currentPerson->confirmFaces();

            // Auch hier: mergeIds verarbeiten
            foreach ($this->getMergePersons() as $p) {
                foreach ($p->getVideoFaces() as $face) {
                    $face->setPerson($currentPerson);
                    $face->setIsVerified(true);
                }
                $p->setStatus(PersonStatus::MERGED);
                $p->setMergedInto($currentPerson);
                $p->setIdentified(true);
            }
        } else {
            // Nichts ausgewählt oder eingegeben
            return null;
        }

        $this->entityManager->flush();

        if ($this->fromQuickResolve) {
            $this->addFlash('success', 'Person erfolgreich identifiziert.');
            return $this->redirectToRoute('app_identity_quick_resolve');
        }

        if ($this->fromTinder) {
            $this->addFlash('success', 'Person erfolgreich identifiziert.');
            return $this->redirectToRoute('app_identity_tinder_resolve');
        }

        $this->resetForm();
        $this->loadNextBestPerson();

        return null;
    }

    #[LiveAction]
    public function skip(): ?RedirectResponse
    {
        // Aus Quick/Tinder kommend: showCount erhöhen, sonst taucht dieselbe Person sofort wieder auf
        if ($this->fromQuickResolve || $this->fromTinder) {
            $this->bumpShowCount();

            return $this->redirectToRoute(
                $this->fromQuickResolve ? 'app_identity_quick_resolve' : 'app_identity_tinder_resolve',
            );
        }

        if ($this->currentPersonId && !in_array($this->currentPersonId, $this->skippedIds, true)) {
            $this->skippedIds[] = $this->currentPersonId;
        }

        $this->resetForm();
        $this->loadNextBestPerson();

        return null;
    }

    /**
     * Übersprungene wieder zulassen und von vorne suchen.
     */
    #[LiveAction]
    public function reload(): void
    {
        $this->skippedIds = [];
        $this->currentPersonId = null;
        $this->resetForm();
        $this->loadNextBestPerson();
    }

    #[LiveAction]
    public function markForReassignment(): ?RedirectResponse
    {
        $person = $this->getUnidentifiedPerson();
        if (!$person) {
            return null;
        }

        $person->setStatus(PersonStatus::TO_REASSIGN);
        $this->entityManager->flush();

        if ($this->fromQuickResolve) {
            return $this->redirectToRoute('app_identity_quick_resolve');
        }
        if ($this->fromTinder) {
            return $this->redirectToRoute('app_identity_tinder_resolve');
        }

        $this->resetForm();
        $this->loadNextBestPerson();
        return null;
    }

    private function bumpShowCount(): void
    {
        $person = $this->getUnidentifiedPerson();
        if (!$person) {
            return;
        }

        $person->setShowCount($person->getShowCount() + 1);
        if ($person->getShowCount() >= self::MAX_SHOWS) {
            $person->setStatus(PersonStatus::UNKNOWN);
        }
        $this->entityManager->flush();
    }

    /**
     * Holt Quick-Vorschläge basierend auf Video-Häufigkeit, globaler Häufigkeit, Geschlecht und Alter
     *
     * @return list<Person>
     */
    public function getQuickSuggestions(): array
    {
        $currentPerson = $this->getUnidentifiedPerson();
        if (!$currentPerson) {
            return [];
        }

        $activeFace = $this->getActiveFace();
        if (!$activeFace) {
            return [];
        }

        $videoId = $activeFace->getMedium()?->getId();
        $targetGender = $activeFace->getGender();
        $targetAge = $activeFace->getAge();

        // Wir holen alle identifizierten Personen
        $allIdentified = $this->personRepository
            ->createQueryBuilder('p')
            ->select('p, COUNT(DISTINCT f_video.id) as video_count, COUNT(DISTINCT f_global.id) as global_count')
            ->leftJoin('p.videoFaces', 'f_video', 'WITH', 'f_video.medium = :videoId')
            ->leftJoin('p.videoFaces', 'f_global')
            ->where('p.status = :status')
            ->setParameter('videoId', $videoId)
            ->setParameter('status', PersonStatus::IDENTIFIED->value)
            ->groupBy('p.id')
            ->getQuery()
            ->getResult();

        // Manuelle Sortierung nach den komplexen Kriterien
        usort($allIdentified, static function ($a_data, $b_data) use ($targetGender, $targetAge) {
            $a = $a_data[0];
            $b = $b_data[0];

            // 1. Häufigkeit im Video
            if ($a_data['video_count'] !== $b_data['video_count']) {
                return $b_data['video_count'] <=> $a_data['video_count'];
            }

            // 2. Häufigkeit überhaupt
            if ($a_data['global_count'] !== $b_data['global_count']) {
                return $b_data['global_count'] <=> $a_data['global_count'];
            }

            // 3. Geschlecht
            $aGenderMatch = $targetGender && $a->getProbablyGender() === $targetGender ? 1 : 0;
            $bGenderMatch = $targetGender && $b->getProbablyGender() === $targetGender ? 1 : 0;
            if ($aGenderMatch !== $bGenderMatch) {
                return $bGenderMatch <=> $aGenderMatch;
            }

            // 4. Ähnliches Alter
            $aAge = $a->getProfileFace()?->getAge();
            $bAge = $b->getProfileFace()?->getAge();

            if ($targetAge && ($aAge || $bAge)) {
                $aDiff = $aAge ? abs($aAge - $targetAge) : 100;
                $bDiff = $bAge ? abs($bAge - $targetAge) : 100;
                if ($aDiff !== $bDiff) {
                    return $aDiff <=> $bDiff;
                }
            }

            return 0;
        });

        // Nur die Personen-Objekte zurückgeben
        $suggestions = array_map(static fn($item) => $item[0], $allIdentified);

        return array_slice($suggestions, 0, 20);
    }

    #[LiveAction]
    public function selectSuggestedPerson(#[LiveArg] int $id): ?RedirectResponse
    {
        $targetPerson = $this->personRepository->find($id);
        if (!$targetPerson) {
            return null;
        }

        $currentPerson = $this->getUnidentifiedPerson();
        if (!$currentPerson) {
            return null;
        }

        // Dieselbe Logik wie beim Formular-Submit
        $personsToMerge = array_merge([$currentPerson], $this->getMergePersons());
        foreach ($personsToMerge as $p) {
            foreach ($p->getVideoFaces() as $face) {
                $face->setPerson($targetPerson);
                $face->setIsVerified(true);
            }
            if ($p !== $targetPerson) {
                $p->setStatus(PersonStatus::MERGED);
                $p->setMergedInto($targetPerson);
                $p->setIdentified(true);
                if ($p->getName() === null || $p->getName() === '') {
                    $p->setName($targetPerson->getName() . ' (merged)');
                }
            }
        }

        $this->entityManager->flush();

        if ($this->fromQuickResolve) {
            $this->addFlash('success', 'Person erfolgreich identifiziert und zusammengeführt.');
            return $this->redirectToRoute('app_identity_quick_resolve');
        }

        if ($this->fromTinder) {
            $this->addFlash('success', 'Person erfolgreich identifiziert.');
            return $this->redirectToRoute('app_identity_tinder_resolve');
        }

        $this->resetForm();
        $this->loadNextBestPerson();

        return null;
    }

    /**
     * @return list<Person>
     */
    private function getMergePersons(): array
    {
        if (empty($this->mergeIds)) {
            return [];
        }

        return $this->personRepository->findBy(['id' => $this->mergeIds]);
    }

    /**
     * Noch offene Personen (ohne die übersprungenen, inkl. der aktuellen).
     */
    public function getRemainingCount(): int
    {
        return (int) $this->createCandidateQueryBuilder()->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
    }

    public function getSkippedCount(): int
    {
        return count($this->skippedIds);
    }
}
