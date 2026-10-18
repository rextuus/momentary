<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FaceRecognitionSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Einstellungen der Gesichtserkennung, im laufenden Betrieb über /admin/settings/face-recognition änderbar.
 * Es gibt genau eine Zeile; solange keine gespeichert ist, gelten die Standardwerte unten.
 */
#[ORM\Entity(repositoryClass: FaceRecognitionSettingsRepository::class)]
class FaceRecognitionSettings
{
    public const array QUALITY_FILTERS = ['NONE', 'LOW', 'MEDIUM', 'HIGH', 'AUTO'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Rekognition-Qualitätsfilter beim Indizieren; LOW lässt bei alten Videos noch die meisten Gesichter durch. */
    #[ORM\Column(length: 10)]
    #[Assert\Choice(choices: self::QUALITY_FILTERS)]
    private string $qualityFilter = 'LOW';

    /** Höchstens so viele Gesichter pro Frame (die größten zuerst). */
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 100)]
    private int $maxFacesPerFrame = 20;

    /** Kleinere Gesichter (Anteil an der Bildbreite) werden verworfen: zu wenig Pixel für einen verlässlichen Abgleich. */
    #[ORM\Column]
    #[Assert\Range(min: 0, max: 0.5)]
    private float $minFaceWidth = 0.03;

    /** Ab dieser Ähnlichkeit liefert Rekognition Kandidaten; entschieden wird erst mit den Schwellen unten. */
    #[ORM\Column]
    #[Assert\Range(min: 0, max: 100)]
    private float $candidateThreshold = 70.0;

    /** So viele Kandidaten pro Gesicht werden abgefragt und gespeichert. */
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 50)]
    private int $maxCandidates = 10;

    /** Ab dieser Ähnlichkeit wird ein Gesicht direkt bei der Analyse einer vorhandenen Person zugeordnet. */
    #[ORM\Column]
    #[Assert\Range(min: 0, max: 100)]
    private float $matchThreshold = 90.0;

    /** Ab dieser Ähnlichkeit wird eine identifizierte Person als Vorschlag am Gesicht hinterlegt. */
    #[ORM\Column]
    #[Assert\Range(min: 0, max: 100)]
    private float $suggestionThreshold = 80.0;

    /**
     * Ab dieser Ähnlichkeit werden Gesichter innerhalb eines Videos zur selben Person gruppiert.
     * Darf niedriger als die Zuordnungsschwelle sein: Gesichter aus demselben Frame werden nie zusammengelegt.
     */
    #[ORM\Column]
    #[Assert\Range(min: 0, max: 100)]
    private float $videoLinkThreshold = 80.0;

    /** Gesichter an fast gleicher Bildposition in aufeinanderfolgenden Frames einer Szene als dieselbe Person werten. */
    #[ORM\Column]
    private bool $trackingEnabled = true;

    /** Mindestüberlappung der Bounding Boxes (Intersection over Union) fürs Tracking. */
    #[ORM\Column]
    #[Assert\Range(min: 0, max: 1)]
    private float $trackingMinIou = 0.3;

    /** Höchster zeitlicher Abstand zweier Frames fürs Tracking. */
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 60)]
    private int $trackingMaxGapSeconds = 6;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return 'NONE'|'LOW'|'MEDIUM'|'HIGH'|'AUTO'
     */
    public function getQualityFilter(): string
    {
        return match ($this->qualityFilter) {
            'NONE' => 'NONE',
            'LOW' => 'LOW',
            'MEDIUM' => 'MEDIUM',
            'HIGH' => 'HIGH',
            default => 'AUTO',
        };
    }

    public function setQualityFilter(string $qualityFilter): static
    {
        $this->qualityFilter = $qualityFilter;

        return $this;
    }

    public function getMaxFacesPerFrame(): int
    {
        return $this->maxFacesPerFrame;
    }

    public function setMaxFacesPerFrame(int $maxFacesPerFrame): static
    {
        $this->maxFacesPerFrame = $maxFacesPerFrame;

        return $this;
    }

    public function getMinFaceWidth(): float
    {
        return $this->minFaceWidth;
    }

    public function setMinFaceWidth(float $minFaceWidth): static
    {
        $this->minFaceWidth = $minFaceWidth;

        return $this;
    }

    public function getCandidateThreshold(): float
    {
        return $this->candidateThreshold;
    }

    public function setCandidateThreshold(float $candidateThreshold): static
    {
        $this->candidateThreshold = $candidateThreshold;

        return $this;
    }

    public function getMaxCandidates(): int
    {
        return $this->maxCandidates;
    }

    public function setMaxCandidates(int $maxCandidates): static
    {
        $this->maxCandidates = $maxCandidates;

        return $this;
    }

    public function getMatchThreshold(): float
    {
        return $this->matchThreshold;
    }

    public function setMatchThreshold(float $matchThreshold): static
    {
        $this->matchThreshold = $matchThreshold;

        return $this;
    }

    public function getSuggestionThreshold(): float
    {
        return $this->suggestionThreshold;
    }

    public function setSuggestionThreshold(float $suggestionThreshold): static
    {
        $this->suggestionThreshold = $suggestionThreshold;

        return $this;
    }

    public function getVideoLinkThreshold(): float
    {
        return $this->videoLinkThreshold;
    }

    public function setVideoLinkThreshold(float $videoLinkThreshold): static
    {
        $this->videoLinkThreshold = $videoLinkThreshold;

        return $this;
    }

    public function isTrackingEnabled(): bool
    {
        return $this->trackingEnabled;
    }

    public function setTrackingEnabled(bool $trackingEnabled): static
    {
        $this->trackingEnabled = $trackingEnabled;

        return $this;
    }

    public function getTrackingMinIou(): float
    {
        return $this->trackingMinIou;
    }

    public function setTrackingMinIou(float $trackingMinIou): static
    {
        $this->trackingMinIou = $trackingMinIou;

        return $this;
    }

    public function getTrackingMaxGapSeconds(): int
    {
        return $this->trackingMaxGapSeconds;
    }

    public function setTrackingMaxGapSeconds(int $trackingMaxGapSeconds): static
    {
        $this->trackingMaxGapSeconds = $trackingMaxGapSeconds;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Kandidaten unterhalb dieser Schwelle liefert AWS gar nicht erst; darüberliegende Schwellen liefen sonst ins Leere.
     */
    #[Assert\Callback]
    public function validateThresholdOrder(ExecutionContextInterface $context): void
    {
        if (
            $this->candidateThreshold > min(
                $this->videoLinkThreshold,
                $this->suggestionThreshold,
                $this->matchThreshold,
            )
        ) {
            $context
                ->buildViolation(
                    'Muss unter allen anderen Schwellen liegen, sonst liefert AWS die nötigen Treffer gar nicht.',
                )
                ->atPath('candidateThreshold')
                ->addViolation();
        }
    }
}
