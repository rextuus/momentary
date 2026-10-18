<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Link, mit dem jemand ohne Konto ein Ereignis (Album) oder ein einzelnes Video/Foto ansehen kann.
 *
 * Der Token ist zufällig (256 Bit) und steht im Link. Er wird im Klartext gespeichert, damit der Ersteller den Link
 * später erneut kopieren kann; wer die Datenbank liest, kann also geteilte Inhalte sehen (wie bei den meisten
 * Foto-Diensten). Ein optionales Passwort wird nur als Hash gespeichert.
 */
#[ORM\Entity]
#[ORM\Index(columns: ['created_by_id'])]
class ShareLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Event $event = null;

    #[ORM\ManyToOne(targetEntity: Medium::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Medium $medium = null;

    /** Geteilt wird nur, was diese Person selbst sehen darf */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passwordHash = null;

    /** Originaldateien herunterladen erlaubt */
    #[ORM\Column(options: ['default' => false])]
    private bool $allowDownload = false;

    /** Notiz des Erstellers, z. B. „für Oma“ */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $viewCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastViewedAt = null;

    public function __construct(Event|Medium $target, User $createdBy, ?\DateTimeImmutable $expiresAt)
    {
        $this->token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        if ($target instanceof Event) {
            $this->event = $target;
        } else {
            $this->medium = $target;
        }
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function getMedium(): ?Medium
    {
        return $this->medium;
    }

    public function getTitle(): string
    {
        return (string) ($this->event?->getTitle() ?? $this->medium?->getTitle());
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(): void
    {
        $this->revokedAt ??= new \DateTimeImmutable();
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    public function isActive(?\DateTimeImmutable $now = null): bool
    {
        return $this->revokedAt === null && !$this->isExpired($now);
    }

    /** Für die Anzeige: aktiv, abgelaufen, widerrufen */
    public function getStatusLabel(): string
    {
        return match (true) {
            $this->revokedAt !== null => 'widerrufen',
            $this->isExpired() => 'abgelaufen',
            default => 'aktiv',
        };
    }

    public function hasPassword(): bool
    {
        return $this->passwordHash !== null;
    }

    public function setPassword(#[\SensitiveParameter] ?string $password): static
    {
        $this->passwordHash =
            $password === null || $password === '' ? null : password_hash($password, PASSWORD_DEFAULT);

        return $this;
    }

    public function checkPassword(#[\SensitiveParameter] string $password): bool
    {
        return $this->passwordHash === null || password_verify($password, $this->passwordHash);
    }

    public function isAllowDownload(): bool
    {
        return $this->allowDownload;
    }

    public function setAllowDownload(bool $allowDownload): static
    {
        $this->allowDownload = $allowDownload;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $label = $label === null ? null : mb_substr(trim($label), 0, 120);
        $this->label = $label === '' ? null : $label;

        return $this;
    }

    public function getViewCount(): int
    {
        return $this->viewCount;
    }

    public function getLastViewedAt(): ?\DateTimeImmutable
    {
        return $this->lastViewedAt;
    }

    public function recordView(): void
    {
        $this->viewCount++;
        $this->lastViewedAt = new \DateTimeImmutable();
    }
}
