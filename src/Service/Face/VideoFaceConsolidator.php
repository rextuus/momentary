<?php

declare(strict_types=1);

namespace App\Service\Face;

use App\Entity\Person;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Enum\PersonStatus;
use App\Repository\VideoFaceRepository;
use App\Service\Face\Clustering\FaceClusterer;
use App\Service\Face\Clustering\FaceNode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Zweite Erkennungsebene: gruppiert alle Gesichter eines Videos und ordnet jeder Gruppe genau eine Person zu.
 *
 * Bei der Analyse wird jedes Gesicht einzeln und mit strenger Schwelle zugeordnet. Hier wird das ganze Video
 * betrachtet: niedrigere Schwelle, Tracking über aufeinanderfolgende Frames, aber nie zwei Gesichter desselben
 * Frames in einer Gruppe. Vom Nutzer bearbeitete Personen und verifizierte Gesichter bleiben fest.
 *
 * Arbeitet nur mit gespeicherten Daten und kann daher mit geänderten Einstellungen beliebig oft wiederholt werden.
 */
class VideoFaceConsolidator
{
    public function __construct(
        private readonly VideoFaceRepository $videoFaceRepository,
        private readonly FaceClusterer $clusterer,
        private readonly FaceRecognitionSettingsProvider $settingsProvider,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function consolidate(Video $video): ConsolidationResult
    {
        $faces = $this->videoFaceRepository->findByVideoForConsolidation($video);
        if ($faces === []) {
            return new ConsolidationResult(0, 0, 0, 0, 0);
        }

        /** @var array<int, VideoFace> $facesById */
        $facesById = [];
        $nodes = [];
        foreach ($faces as $face) {
            $node = $this->toNode($face);
            if ($node !== null) {
                $facesById[$node->id] = $face;
                $nodes[] = $node;
            }
        }

        $settings = $this->settingsProvider->get();
        $clusters = $this->clusterer->cluster(
            $nodes,
            $this->settingsProvider->thresholds()->videoLink,
            $settings->isTrackingEnabled(),
            $settings->getTrackingMinIou(),
            $settings->getTrackingMaxGapSeconds(),
        );

        $personsBefore = $this->distinctPersons($faces);
        $seenElsewhere = $this->videoFaceRepository->findPersonIdsSeenOutsideVideo(array_keys($personsBefore), $video);

        $reassigned = 0;
        foreach ($clusters as $cluster) {
            $clusterFaces = array_map(static fn(FaceNode $node): VideoFace => $facesById[$node->id], $cluster);
            $target = $this->choosePerson($cluster, $clusterFaces, $seenElsewhere);

            foreach ($clusterFaces as $face) {
                $current = $face->getPerson();
                if ($current === $target) {
                    continue;
                }
                $current?->removeVideoFace($face);
                $face->setPerson($target);
                $target->addVideoFace($face);
                $reassigned++;
            }
        }

        $removed = $this->removeOrphanedAutoPersons($personsBefore);
        $this->entityManager->flush();

        return new ConsolidationResult(
            faces: \count($faces),
            personsBefore: \count($personsBefore),
            personsAfter: \count($this->distinctPersons($faces)),
            reassignedFaces: $reassigned,
            removedPersons: $removed,
        );
    }

    private function toNode(VideoFace $face): ?FaceNode
    {
        $id = $face->getId();
        $person = $face->getPerson();
        if ($id === null || $person === null) {
            return null;
        }

        $resolved = FaceMatcher::resolveMerged($person);
        $isAnchor = $face->isVerified() || $resolved->getStatus() !== PersonStatus::NEW;

        return new FaceNode(
            id: $id,
            // Alle Gesichter eines Frames teilen sich dasselbe Frame-Bild
            frame: $face->getFaceImage()?->getId() !== null
                ? 'file:' . $face->getFaceImage()->getId()
                : 'ts:' . $face->getTimestamp(),
            label: $face->getFaceLabel(),
            candidates: $face->getMatchCandidates(),
            sceneId: $face->getVideoScene()?->getId(),
            timestamp: (int) $face->getTimestamp(),
            boundingBox: $face->getBoundingBox() ?? [],
            anchor: $isAnchor ? $resolved->getId() : null,
        );
    }

    /**
     * Feste Person > Person, die schon in anderen Videos erkannt wurde > häufigste Person der Gruppe.
     *
     * @param list<FaceNode>  $cluster
     * @param list<VideoFace> $clusterFaces
     * @param list<int>       $seenElsewhere
     */
    private function choosePerson(array $cluster, array $clusterFaces, array $seenElsewhere): Person
    {
        $anchor = null;
        foreach ($cluster as $node) {
            $anchor ??= $node->anchor;
        }

        $persons = [];
        $votes = [];
        foreach ($clusterFaces as $face) {
            $person = $face->getPerson();
            if ($person === null) {
                continue;
            }
            $person = FaceMatcher::resolveMerged($person);
            $id = (int) $person->getId();
            if ($id === $anchor) {
                return $person;
            }
            $persons[$id] = $person;
            // Videoübergreifende Treffer wiegen deutlich mehr als bloße Häufigkeit im Video
            $votes[$id] = ($votes[$id] ?? 0) + (\in_array($id, $seenElsewhere, true) ? 1000 : 1);
        }
        arsort($votes);

        return $persons[(int) array_key_first($votes)];
    }

    /**
     * @param list<VideoFace> $faces
     *
     * @return array<int, Person>
     */
    private function distinctPersons(array $faces): array
    {
        $persons = [];
        foreach ($faces as $face) {
            $person = $face->getPerson();
            if ($person !== null && $person->getId() !== null) {
                $persons[$person->getId()] = $person;
            }
        }

        return $persons;
    }

    /**
     * Automatisch angelegte Personen, die durch die Gruppierung leer geworden sind, entfernen.
     *
     * @param array<int, Person> $persons
     */
    private function removeOrphanedAutoPersons(array $persons): int
    {
        $removed = 0;
        foreach ($persons as $person) {
            if (
                $person->getStatus() === PersonStatus::NEW
                && $person->getVideoFaces()->isEmpty()
                && $person->getDetectionFaces()->isEmpty()
            ) {
                $this->entityManager->remove($person);
                $removed++;
            }
        }

        return $removed;
    }
}
