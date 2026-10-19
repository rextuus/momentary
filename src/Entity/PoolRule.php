<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Regel eines Pools: Medien, auf die **alle** gesetzten Bedingungen zutreffen, gehören zum Pool (z. B. Tag
 * „Heimvideos“ und vor 2005). Ein Pool kann mehrere Regeln haben; es genügt, wenn eine passt.
 *
 * Ausgewertet wird die Regel in MediumRepository::visibleCondition().
 */
#[ORM\Entity]
class PoolRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: UserGroup::class, inversedBy: 'rules')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private UserGroup $pool;

    /** Medien dieses Ereignisses */
    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Event $event = null;

    /** Medien mit diesem Tag (am ganzen Medium, z. B. Upload-Ordner oder Ort) */
    #[ORM\ManyToOne(targetEntity: Tag::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tag $tag = null;

    /** Aufnahme ab diesem Tag (einschließlich) */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $takenFrom = null;

    /** Aufnahme bis zu diesem Tag (einschließlich) */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $takenUntil = null;

    public function __construct(UserGroup $pool)
    {
        $this->pool = $pool;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPool(): UserGroup
    {
        return $this->pool;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): static
    {
        $this->event = $event;

        return $this;
    }

    public function getTag(): ?Tag
    {
        return $this->tag;
    }

    public function setTag(?Tag $tag): static
    {
        $this->tag = $tag;

        return $this;
    }

    public function getTakenFrom(): ?\DateTimeImmutable
    {
        return $this->takenFrom;
    }

    public function getTakenUntil(): ?\DateTimeImmutable
    {
        return $this->takenUntil;
    }

    public function setPeriod(?\DateTimeImmutable $from, ?\DateTimeImmutable $until): static
    {
        $this->takenFrom = $from?->setTime(0, 0);
        $this->takenUntil = $until?->setTime(0, 0);

        return $this;
    }

    /** Ohne Bedingung würde die Regel alles freigeben */
    public function hasCondition(): bool
    {
        return $this->event !== null || $this->tag !== null || $this->takenFrom !== null || $this->takenUntil !== null;
    }

    /** Für die Anzeige, z. B. „Tag Heimvideos · bis 31.12.2004“ */
    public function describe(): string
    {
        $parts = [];
        if ($this->event !== null) {
            $parts[] = 'Ereignis „' . $this->event->getTitle() . '“';
        }
        if ($this->tag !== null) {
            $parts[] = 'Tag „' . $this->tag->getName() . '“';
        }
        if ($this->takenFrom !== null && $this->takenUntil !== null) {
            $parts[] = sprintf(
                'aufgenommen %s – %s',
                $this->takenFrom->format('d.m.Y'),
                $this->takenUntil->format('d.m.Y'),
            );
        } elseif ($this->takenFrom !== null) {
            $parts[] = 'aufgenommen ab ' . $this->takenFrom->format('d.m.Y');
        } elseif ($this->takenUntil !== null) {
            $parts[] = 'aufgenommen bis ' . $this->takenUntil->format('d.m.Y');
        }

        return implode(' · ', $parts);
    }
}
