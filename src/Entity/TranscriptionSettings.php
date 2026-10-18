<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TranscriptionSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Einstellungen der Ton-Transkription, über /admin/settings/transcription änderbar.
 * Es gibt genau eine Zeile; solange keine gespeichert ist, gelten die Standardwerte unten.
 */
#[ORM\Entity(repositoryClass: TranscriptionSettingsRepository::class)]
class TranscriptionSettings
{
    /** Whisper-Modelle, von schnell/ungenau bis langsam/genau */
    public const array MODELS = ['small', 'medium', 'large-v3-turbo', 'large-v3'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** large-v3-turbo: auf einem i5-6500T etwa Echtzeit, deutlich besser bei Namen und Dialekt als small */
    #[ORM\Column(length: 32)]
    #[Assert\Choice(choices: self::MODELS)]
    private string $model = 'large-v3-turbo';

    /** CPU-Threads; einen Kern frei lassen, damit andere Dienste auf dem Server reaktionsfähig bleiben */
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 32)]
    private int $threads = 3;

    /** Sprachcode wie "de"; leer = automatisch erkennen (kostet bei kurzen Clips manchmal Treffsicherheit) */
    #[ORM\Column(length: 8, nullable: true)]
    #[Assert\Regex(pattern: '/^[a-z]{2,3}$/', message: 'Zwei- oder dreistelliger Sprachcode, z. B. "de".')]
    private ?string $language = 'de';

    /** Als letzter Schritt jeder Video-Analyse transkribieren (sonst nur per Button/Command) */
    #[ORM\Column(options: ['default' => true])]
    private bool $transcribeInPipeline = true;

    /** Nach der Transkription Tags je Szene aus dem Gesprochenen ableiten (ein Gemini-Aufruf nur mit Text) */
    #[ORM\Column]
    private bool $deriveTags = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function getThreads(): int
    {
        return $this->threads;
    }

    public function setThreads(int $threads): static
    {
        $this->threads = $threads;

        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): static
    {
        $this->language = $language === '' ? null : $language;

        return $this;
    }

    public function isTranscribeInPipeline(): bool
    {
        return $this->transcribeInPipeline;
    }

    public function setTranscribeInPipeline(bool $transcribeInPipeline): static
    {
        $this->transcribeInPipeline = $transcribeInPipeline;

        return $this;
    }

    public function isDeriveTags(): bool
    {
        return $this->deriveTags;
    }

    public function setDeriveTags(bool $deriveTags): static
    {
        $this->deriveTags = $deriveTags;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
