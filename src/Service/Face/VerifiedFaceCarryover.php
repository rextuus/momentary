<?php

declare(strict_types=1);

namespace App\Service\Face;

use App\Entity\Person;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Repository\VideoFaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Bestätigte Gesichter überstehen das Neuverarbeiten.
 *
 * Vor dem Entfernen der alten Gesichter merkt remember() Zeitpunkt, Box und Person jedes bestätigten Gesichts am
 * Video. Nach der Analyse, vor der Konsolidierung, ordnet apply() jedem gemerkten Gesicht höchstens ein neues
 * Gesicht zu (CarryoverMatcher, Toleranz ein halbes Frame-Intervall). Das neue Gesicht übernimmt Person und
 * Bestätigung und ist damit Anker der Konsolidierung. Alles andere bleibt beim Abgleich über die Embeddings; nur
 * automatisch zugeordnete Gesichter werden also neu entschieden.
 */
class VerifiedFaceCarryover
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoFaceRepository $videoFaceRepository,
        private readonly OrphanedPersonCleaner $orphanedPersonCleaner,
        #[Autowire('%env(default:app.frame_analysis_fps:FRAME_ANALYSIS_FPS)%')]
        private readonly float $defaultFps = 0.2,
    ) {}

    /**
     * Bestätigte Gesichter des Videos merken (zusätzlich zu noch nicht übertragenen aus einem abgebrochenen Lauf).
     *
     * @return list<int> Personen der gemerkten Gesichter; sie dürfen beim Entfernen der Gesichter nicht wegfallen
     */
    public function remember(Video $video): array
    {
        $carryover = $video->getVerifiedFaceCarryover();
        foreach ($this->videoFaceRepository->findBy(['medium' => $video, 'isVerified' => true]) as $face) {
            $person = $face->getPerson();
            $box = $face->getBoundingBox();
            if ($person === null || $box === null || $box === []) {
                continue;
            }
            $carryover[] = [
                'timestamp' => (int) $face->getTimestamp(),
                'box' => array_map(floatval(...), $box),
                'person' => (int) FaceMatcher::resolveMerged($person)->getId(),
            ];
        }
        $video->setVerifiedFaceCarryover($carryover);
        $this->entityManager->flush();

        return array_values(array_unique(array_column($carryover, 'person')));
    }

    /**
     * Gemerkte Gesichter auf die neu erkannten übertragen und die Merkliste leeren.
     *
     * @return int Anzahl übertragener Bestätigungen
     */
    public function apply(Video $video): int
    {
        $carryover = $video->getVerifiedFaceCarryover();
        if ($carryover === []) {
            return 0;
        }

        $faces = $this->videoFaceRepository->findBy(['medium' => $video, 'isVerified' => false]);
        $persons = $this->existingPersons($carryover);
        $available = array_values(array_filter(
            $carryover,
            static fn(array $entry): bool => isset($persons[$entry['person']]),
        ));

        /** @var list<int> $released bisherige Personen der übernommenen Gesichter */
        $released = [];
        /** @var array<string, list<int>> $personsInFrame übertragene Personen je Frame */
        $personsInFrame = [];
        $matches = CarryoverMatcher::match($available, $faces, $this->toleranceSeconds($video));
        foreach ($matches as ['face' => $face, 'person' => $personId]) {
            $released[] = (int) $face->getPerson()?->getId();
            $this->assign($face, $persons[$personId]);
            $face->setIsVerified(true);
            $personsInFrame[CarryoverMatcher::frameKey($face)][] = (int) $persons[$personId]->getId();
        }

        $this->separateFromCarriedPersons($faces, $personsInFrame);

        $video->setVerifiedFaceCarryover([]);
        $this->entityManager->flush();

        // Leer gewordene automatische Personen aufräumen (auch nicht wiedergefundene); bearbeitete bleiben
        $this->orphanedPersonCleaner->removeOrphans(array_values(array_unique([
            ...$released,
            ...array_column($carryover, 'person'),
        ])));

        return \count($matches);
    }

    /**
     * Gemerkte Personen, die es noch gibt (zusammengeführte aufgelöst), nach gemerkter ID.
     *
     * @param list<array{timestamp: int, box: array<string, float>, person: int}> $carryover
     *
     * @return array<int, Person>
     */
    private function existingPersons(array $carryover): array
    {
        $persons = [];
        foreach (array_unique(array_column($carryover, 'person')) as $id) {
            $person = $this->entityManager->find(Person::class, $id);
            if ($person !== null) {
                $persons[$id] = FaceMatcher::resolveMerged($person);
            }
        }

        return $persons;
    }

    /**
     * Halbes Intervall der Analyse-Frames, mindestens eine Sekunde (Zeitstempel sind ganze Sekunden).
     */
    private function toleranceSeconds(Video $video): float
    {
        $fps = $video->getAnalysisFps() ?? $this->defaultFps;

        return $fps > 0.0 ? max(1.0, 0.5 / $fps) : 1.0;
    }

    /**
     * Ein neues Gesicht, das beim Abgleich automatisch dieselbe Person bekam wie ein bestätigtes Gesicht im selben
     * Frame, erhält eine eigene automatische Person; die Konsolidierung entscheidet dann neu.
     *
     * @param list<VideoFace>          $faces
     * @param array<string, list<int>> $personsInFrame
     */
    private function separateFromCarriedPersons(array $faces, array $personsInFrame): void
    {
        foreach ($faces as $face) {
            if ($face->isVerified() || $face->getPerson() === null) {
                continue;
            }
            $personId = (int) FaceMatcher::resolveMerged($face->getPerson())->getId();
            if (!\in_array($personId, $personsInFrame[CarryoverMatcher::frameKey($face)] ?? [], true)) {
                continue;
            }
            $person = new Person();
            $person->setName('unknown_' . substr((string) $face->getFaceLabel(), 0, 8));
            $person->setIdentified(false);
            $this->entityManager->persist($person);
            // Erst speichern: Person::videoFaces hat orphanRemoval, und nur die Collection einer gespeicherten Person
            // hebt beim Hinzufügen das Löschen des umgehängten Gesichts wieder auf
            $this->entityManager->flush();
            $this->assign($face, $person);
        }
    }

    private function assign(VideoFace $face, Person $person): void
    {
        if ($face->getPerson() === $person) {
            return;
        }
        $face->getPerson()?->removeVideoFace($face);
        $face->setPerson($person);
        $person->addVideoFace($face);
    }
}
