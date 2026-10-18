<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ein Ereignis bzw. Album (z. B. "Mallorca 2025"), das Videos und Fotos zusammenfasst.
 */
#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'media_event')]
class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Bitte einen Titel angeben.')]
    #[Assert\Length(max: 255)]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Assert\Expression(
        'this.getEndDate() === null or this.getStartDate() === null or this.getEndDate() >= this.getStartDate()',
        message: 'Das Ende darf nicht vor dem Beginn liegen.',
    )]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $owner = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, Medium>
     */
    #[ORM\ManyToMany(targetEntity: Medium::class, inversedBy: 'events')]
    #[ORM\JoinTable(name: 'media_event_medium')]
    private Collection $media;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->media = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, Medium>
     */
    public function getMedia(): Collection
    {
        return $this->media;
    }

    /**
     * Medien nach Aufnahmedatum sortiert.
     *
     * @return list<Medium>
     */
    public function getSortedMedia(): array
    {
        $media = array_values($this->media->toArray());
        usort($media, static fn(Medium $a, Medium $b): int => $a->getDate() <=> $b->getDate());

        return $media;
    }

    public function addMedium(Medium $medium): static
    {
        if (!$this->media->contains($medium)) {
            $this->media->add($medium);
            $medium->addEvent($this);
        }

        return $this;
    }

    public function removeMedium(Medium $medium): static
    {
        if ($this->media->removeElement($medium)) {
            $medium->removeEvent($this);
        }

        return $this;
    }

    /**
     * Personen über alle Medien, häufigste zuerst (nur benannte; unbekannte Gesichter zählen nicht).
     *
     * @return list<array{person: Person, count: int}>
     */
    public function getPersonSummary(): array
    {
        $persons = [];
        foreach ($this->media as $medium) {
            foreach ($medium->getFaces() as $face) {
                $person = $face->getPerson();
                if ($person === null || $person->getId() === null || $person->hasPlaceholderName()) {
                    continue;
                }
                $persons[$person->getId()] ??= ['person' => $person, 'count' => 0];
                $persons[$person->getId()]['count']++;
            }
        }
        usort($persons, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return $persons;
    }
}
