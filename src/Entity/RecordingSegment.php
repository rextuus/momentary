<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Aufnahme innerhalb eines Videos, z. B. ein Tag auf einer digitalisierten MiniDV-Kassette
 * (documentation/dv_aufnahmedatum.md). Bereich im Video plus Aufnahmezeit laut Kamera; ohne gültiges Datum
 * (analog überspielte Hi8-Kassette, nie gestellte Uhr) ist recordedAt null.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_recording_segment_camera_time', columns: ['camera_recorded_at'])]
class RecordingSegment
{
    public const string SOURCE_DV = 'dv';
    public const string SOURCE_MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'recordingSegments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Video $video = null;

    #[ORM\Column]
    private float $startSeconds = 0.0;

    #[ORM\Column]
    private float $endSeconds = 0.0;

    /** Aufnahmezeit (UTC); null = unbekannt */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $recordedAt = null;

    /** Aufnahmezeit, wie die Kamera sie geliefert hat (Ortszeit als Europe/Berlin gelesen), auch nach Korrekturen */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cameraRecordedAt = null;

    #[ORM\Column(length: 16)]
    private string $source = self::SOURCE_DV;

    /**
     * Vermutlich Werksdatum nach leerem Pufferakku (1. Januar, erste Stunde): Datum bleibt gespeichert, zählt aber
     * nicht als verlässlich.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $suspicious = false;

    /**
     * Derselbe Kameratag kommt auch auf anderen Kassetten vor (RecurringRecordingDates). Nur ein Hinweis zur Prüfung:
     * bei Hochzeit, Urlaub oder Weihnachten normal, das Datum gilt weiter.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $recurringDate = false;

    public function __construct(
        float $startSeconds,
        float $endSeconds,
        ?\DateTimeImmutable $cameraRecordedAt,
        string $source = self::SOURCE_DV,
    ) {
        $this->startSeconds = $startSeconds;
        $this->endSeconds = $endSeconds;
        $this->cameraRecordedAt = $cameraRecordedAt;
        $this->recordedAt = $cameraRecordedAt;
        $this->source = $source;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVideo(): ?Video
    {
        return $this->video;
    }

    public function setVideo(?Video $video): static
    {
        $this->video = $video;

        return $this;
    }

    public function getStartSeconds(): float
    {
        return $this->startSeconds;
    }

    public function getEndSeconds(): float
    {
        return $this->endSeconds;
    }

    public function getRecordedAt(): ?\DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function getCameraRecordedAt(): ?\DateTimeImmutable
    {
        return $this->cameraRecordedAt;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function isSuspicious(): bool
    {
        return $this->suspicious;
    }

    public function setSuspicious(bool $suspicious): static
    {
        $this->suspicious = $suspicious;

        return $this;
    }

    public function isRecurringDate(): bool
    {
        return $this->recurringDate;
    }

    public function setRecurringDate(bool $recurringDate): static
    {
        $this->recurringDate = $recurringDate;

        return $this;
    }

    /** Datum, das für Sortierung und takenAt zählt: vorhanden und kein Werksdatum (wiederkehrend ist nur ein Hinweis) */
    public function hasReliableDate(): bool
    {
        return $this->recordedAt !== null && !$this->suspicious;
    }
}
