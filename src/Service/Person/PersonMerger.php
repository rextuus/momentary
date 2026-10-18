<?php

declare(strict_types=1);

namespace App\Service\Person;

use App\Entity\Person;
use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use App\Service\Search\MediumIndexer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Personen zusammenführen und benennen, an einer Stelle statt in jeder Oberfläche einzeln.
 *
 * Nach jeder Änderung werden die betroffenen Videos und Fotos neu indexiert, damit Suche und Facetten die Namen kennen.
 */
class PersonMerger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PersonRepository $personRepository,
        private readonly MediumIndexer $indexer,
    ) {}

    /**
     * Alle Gesichter von $source gehen an $target; $source bleibt als "zusammengeführt" mit Verweis erhalten.
     */
    public function merge(Person $source, Person $target): Person
    {
        if ($source === $target) {
            return $target;
        }

        // Nur umhängen, nicht aus $source entfernen: die Sammlung hat orphanRemoval, das Gesicht würde sonst gelöscht
        foreach ($source->getVideoFaces()->toArray() as $face) {
            $face->setPerson($target);
            $target->addVideoFace($face);
        }
        foreach ($source->getDetectionFaces()->toArray() as $face) {
            $source->removeDetectionFace($face);
            $face->setDetection($target);
        }
        if ($target->getProfileFace() === null && $source->getProfileFace() !== null) {
            $target->setProfileFace($source->getProfileFace());
        }
        $source->setProfileFace(null);

        // Verweise anderer zusammengeführter Personen auf das neue Ziel umbiegen
        foreach ($this->personRepository->findBy(['mergedInto' => $source]) as $earlier) {
            $earlier->setMergedInto($target);
        }

        $source->setStatus(PersonStatus::MERGED);
        $source->setMergedInto($target);
        $source->setIdentified(true);
        $this->entityManager->flush();

        $this->reindex($target);

        return $target;
    }

    /**
     * Einer Person einen Namen geben. Gibt es schon eine (nicht zusammengeführte) Person mit diesem Namen, wird
     * zusammengeführt statt eine zweite "Oma" anzulegen.
     *
     * @return Person die Person, die jetzt den Namen trägt
     */
    public function name(Person $person, string $name): Person
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Der Name darf nicht leer sein.');
        }

        $existing = $this->findByName($name);
        if ($existing !== null && $existing !== $person) {
            return $this->merge($person, $existing);
        }

        $person->setName($name);
        $person->setStatus(PersonStatus::IDENTIFIED);
        $person->setIdentified(true);
        $this->entityManager->flush();
        $this->reindex($person);

        return $person;
    }

    public function findByName(string $name): ?Person
    {
        return $this->personRepository
            ->createQueryBuilder('p')
            ->where('LOWER(p.name) = LOWER(:name)')
            ->andWhere('p.status <> :merged')
            ->setParameter('name', trim($name))
            ->setParameter('merged', PersonStatus::MERGED)
            ->orderBy('p.id', \SortDirection::Ascending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function reindex(Person $person): void
    {
        $media = [];
        foreach ($person->getVideoFaces() as $face) {
            $medium = $face->getMedium();
            if ($medium !== null) {
                $media[spl_object_id($medium)] = $medium;
            }
        }

        $this->indexer->reindex($media);
    }
}
