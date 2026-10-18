<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TranscriptionStatus;
use App\Repository\VideoTranscriptRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Transkript des Tons eines Videos. Entsteht nur auf Wunsch (nicht Teil der normalen Pipeline).
 */
#[ORM\Entity(repositoryClass: VideoTranscriptRepository::class)]
class VideoTranscript
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'transcript')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Video $video;

    #[ORM\Column(length: 16, enumType: TranscriptionStatus::class)]
    private TranscriptionStatus $status = TranscriptionStatus::QUEUED;

    /** Whisper-Modell des letzten Laufs */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $model = null;

    /** Erkannte bzw. vorgegebene Sprache */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $language = null;

    /** Bisher verarbeitete Sekunden Ton (für die Fortschrittsanzeige) */
    #[ORM\Column]
    private float $processedSeconds = 0.0;

    #[ORM\Column]
    private float $audioSeconds = 0.0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column]
    private \DateTimeImmutable $queuedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** @var Collection<int, TranscriptSegment> */
    #[ORM\OneToMany(
        targetEntity: TranscriptSegment::class,
        mappedBy: 'transcript',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['startSeconds' => 'ASC'])]
    private Collection $segments;

    public function __construct(Video $video)
    {
        $this->video = $video;
        $this->queuedAt = new \DateTimeImmutable();
        $this->segments = new ArrayCollection();
        $video->setTranscript($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVideo(): Video
    {
        return $this->video;
    }

    public function getStatus(): TranscriptionStatus
    {
        return $this->status;
    }

    public function queue(): void
    {
        $this->status = TranscriptionStatus::QUEUED;
        $this->queuedAt = new \DateTimeImmutable();
        $this->error = null;
        $this->processedSeconds = 0.0;
    }

    public function start(string $model, float $audioSeconds): void
    {
        $this->status = TranscriptionStatus::RUNNING;
        $this->model = $model;
        $this->audioSeconds = $audioSeconds;
        $this->processedSeconds = 0.0;
        $this->startedAt = new \DateTimeImmutable();
        $this->finishedAt = null;
        $this->error = null;
    }

    public function reportProgress(float $processedSeconds): void
    {
        $this->processedSeconds = min($processedSeconds, max($this->audioSeconds, $processedSeconds));
    }

    /**
     * @param list<TranscriptSegment> $segments
     */
    public function complete(?string $language, array $segments): void
    {
        $this->segments->clear();
        foreach ($segments as $segment) {
            $segment->setTranscript($this);
            $this->segments->add($segment);
        }
        $this->language = $language;
        $this->status = TranscriptionStatus::DONE;
        $this->processedSeconds = $this->audioSeconds;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function fail(string $error): void
    {
        $this->status = TranscriptionStatus::FAILED;
        $this->error = mb_substr($error, 0, 4000);
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function getProcessedSeconds(): float
    {
        return $this->processedSeconds;
    }

    public function getAudioSeconds(): float
    {
        return $this->audioSeconds;
    }

    /** 0–100, solange die Transkription läuft */
    public function getPercent(): int
    {
        if ($this->status === TranscriptionStatus::DONE) {
            return 100;
        }
        if ($this->audioSeconds <= 0.0) {
            return 0;
        }

        return (int) min(99, floor(($this->processedSeconds / $this->audioSeconds) * 100));
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getQueuedAt(): \DateTimeImmutable
    {
        return $this->queuedAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /**
     * @return Collection<int, TranscriptSegment>
     */
    public function getSegments(): Collection
    {
        return $this->segments;
    }

    /**
     * Abschnitte, die zeitlich in den Bereich fallen (z. B. eine Szene).
     *
     * @return list<TranscriptSegment>
     */
    public function segmentsBetween(float $start, float $end): array
    {
        return array_values(
            $this->segments
                ->filter(static fn(TranscriptSegment $segment): bool => $segment->overlaps($start, $end))
                ->toArray(),
        );
    }

    public function getFullText(): string
    {
        return implode(' ', $this->segments->map(static fn(TranscriptSegment $s): string => $s->getText())->toArray());
    }
}
