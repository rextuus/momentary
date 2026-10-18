<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Ein zusammenhängend gesprochener Abschnitt mit Zeitstempeln im Video.
 */
#[ORM\Entity]
#[ORM\Index(columns: ['start_seconds'])]
class TranscriptSegment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'segments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?VideoTranscript $transcript = null;

    public function __construct(
        #[ORM\Column]
        private float $startSeconds,
        #[ORM\Column]
        private float $endSeconds,
        #[ORM\Column(type: 'text')]
        private string $text,
        /** Durchschnittliche Log-Wahrscheinlichkeit der Wörter: nahe 0 = sicher, unter -1 = eher geraten */
        #[ORM\Column]
        private float $avgLogprob = 0.0,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTranscript(): ?VideoTranscript
    {
        return $this->transcript;
    }

    public function setTranscript(?VideoTranscript $transcript): static
    {
        $this->transcript = $transcript;

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

    public function getText(): string
    {
        return $this->text;
    }

    public function getAvgLogprob(): float
    {
        return $this->avgLogprob;
    }

    public function isUncertain(): bool
    {
        return $this->avgLogprob < -0.8;
    }

    public function overlaps(float $start, float $end): bool
    {
        return $this->startSeconds < $end && $this->endSeconds > $start;
    }
}
