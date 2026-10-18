<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Zwei Personen sind laut Benutzer verschiedene Menschen: nicht wieder als Doppelung vorschlagen.
 * Gespeichert mit der kleineren ID zuerst.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['person_a_id', 'person_b_id'])]
class PersonPairDismissal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Person $personA;

    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Person $personB;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Person $one, Person $other)
    {
        [$this->personA, $this->personB] = (int) $one->getId() < (int) $other->getId()
            ? [$one, $other]
            : [$other, $one];
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPersonA(): Person
    {
        return $this->personA;
    }

    public function getPersonB(): Person
    {
        return $this->personB;
    }
}
