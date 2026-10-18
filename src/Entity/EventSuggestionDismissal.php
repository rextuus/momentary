<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Ein Benutzer hat einen Ereignis-Vorschlag verworfen; er wird ihm nicht mehr angezeigt.
 * Kommen neue Medien in den Zeitraum, ändert sich der Schlüssel und der Vorschlag erscheint neu.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['user_id', 'suggestion_key'])]
class EventSuggestionDismissal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 64)]
        private string $suggestionKey,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSuggestionKey(): string
    {
        return $this->suggestionKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
