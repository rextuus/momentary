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
 *
 * Rekognition und InsightFace haben eigene Filter und Schwellen: Rekognition misst Ähnlichkeit in Prozent (90 = sehr
 * ähnlich), InsightFace als Kosinus der Embeddings (0,45 = dieselbe Person). Welche gelten, hängt vom Anbieter in
 * FACE_RECOGNITION_PROVIDER ab (FaceThresholds). Tracking und „Kandidaten pro Gesicht“ gelten für beide.
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

    /** InsightFace: Gesichter mit kürzerer Box-Seite darunter (Pixel im Originalbild) bekommen kein Embedding. */
    #[ORM\Column(options: ['default' => 40])]
    #[Assert\Range(min: 0, max: 500)]
    private int $insightFaceMinFacePx = 40;

    /** InsightFace: höchstens so viele Gesichter pro Bild, die größten zuerst; 0 = alle. */
    #[ORM\Column(options: ['default' => 10])]
    #[Assert\Range(min: 0, max: 100)]
    private int $insightFaceMaxFaces = 10;

    /** InsightFace: ab diesem Kosinus wird ein Gesicht als Kandidat gespeichert. */
    #[ORM\Column(options: ['default' => 0.25])]
    #[Assert\Range(min: 0, max: 1)]
    private float $insightFaceCandidateThreshold = 0.25;

    /** InsightFace: ab diesem Kosinus werden Gesichter innerhalb eines Videos gruppiert. */
    #[ORM\Column(options: ['default' => 0.35])]
    #[Assert\Range(min: 0, max: 1)]
    private float $insightFaceVideoLinkThreshold = 0.35;

    /** InsightFace: ab diesem Kosinus wird eine benannte Person vorgeschlagen. */
    #[ORM\Column(options: ['default' => 0.38])]
    #[Assert\Range(min: 0, max: 1)]
    private float $insightFaceSuggestionThreshold = 0.38;

    /**
     * InsightFace: ab diesem Kosinus wird ein Gesicht direkt einer vorhandenen Person zugeordnet. Bewusst höher als
     * „Vorschlagen“: bei alten, verrauschten Videos lieber ein Vorschlag mehr als eine falsche Zuordnung.
     */
    #[ORM\Column(options: ['default' => 0.5])]
    #[Assert\Range(min: 0, max: 1)]
    private float $insightFaceMatchThreshold = 0.5;

    /**
     * InsightFace: Nur Gesichter ab diesem Detektions-Score dienen als Referenz einer Person. Schlechte Aufnahmen
     * streuen stärker; sie werden trotzdem zugeordnet, prägen aber nicht, wie die Person „aussieht“.
     */
    #[ORM\Column(options: ['default' => 0.7])]
    #[Assert\Range(min: 0, max: 1)]
    private float $insightFaceReferenceMinScore = 0.7;

    /** InsightFace: Nur Gesichter ab dieser Größe (kürzere Seite, Pixel) dienen als Referenz einer Person. */
    #[ORM\Column(options: ['default' => 60])]
    #[Assert\Range(min: 0, max: 500)]
    private int $insightFaceReferenceMinFacePx = 60;

    /**
     * InsightFace: abweichendes geschätztes Geschlecht schließt einen Treffer aus. Standardmäßig aus, weil die Schätzung
     * bei Kindern und schlechten Aufnahmen oft danebenliegt.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $insightFaceGenderCheck = false;

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

    public function getInsightFaceMinFacePx(): int
    {
        return $this->insightFaceMinFacePx;
    }

    public function setInsightFaceMinFacePx(int $insightFaceMinFacePx): static
    {
        $this->insightFaceMinFacePx = $insightFaceMinFacePx;

        return $this;
    }

    public function getInsightFaceMaxFaces(): int
    {
        return $this->insightFaceMaxFaces;
    }

    public function setInsightFaceMaxFaces(int $insightFaceMaxFaces): static
    {
        $this->insightFaceMaxFaces = $insightFaceMaxFaces;

        return $this;
    }

    public function getInsightFaceCandidateThreshold(): float
    {
        return $this->insightFaceCandidateThreshold;
    }

    public function setInsightFaceCandidateThreshold(float $insightFaceCandidateThreshold): static
    {
        $this->insightFaceCandidateThreshold = $insightFaceCandidateThreshold;

        return $this;
    }

    public function getInsightFaceVideoLinkThreshold(): float
    {
        return $this->insightFaceVideoLinkThreshold;
    }

    public function setInsightFaceVideoLinkThreshold(float $insightFaceVideoLinkThreshold): static
    {
        $this->insightFaceVideoLinkThreshold = $insightFaceVideoLinkThreshold;

        return $this;
    }

    public function getInsightFaceSuggestionThreshold(): float
    {
        return $this->insightFaceSuggestionThreshold;
    }

    public function setInsightFaceSuggestionThreshold(float $insightFaceSuggestionThreshold): static
    {
        $this->insightFaceSuggestionThreshold = $insightFaceSuggestionThreshold;

        return $this;
    }

    public function getInsightFaceMatchThreshold(): float
    {
        return $this->insightFaceMatchThreshold;
    }

    public function setInsightFaceMatchThreshold(float $insightFaceMatchThreshold): static
    {
        $this->insightFaceMatchThreshold = $insightFaceMatchThreshold;

        return $this;
    }

    public function getInsightFaceReferenceMinScore(): float
    {
        return $this->insightFaceReferenceMinScore;
    }

    public function setInsightFaceReferenceMinScore(float $insightFaceReferenceMinScore): static
    {
        $this->insightFaceReferenceMinScore = $insightFaceReferenceMinScore;

        return $this;
    }

    public function getInsightFaceReferenceMinFacePx(): int
    {
        return $this->insightFaceReferenceMinFacePx;
    }

    public function setInsightFaceReferenceMinFacePx(int $insightFaceReferenceMinFacePx): static
    {
        $this->insightFaceReferenceMinFacePx = $insightFaceReferenceMinFacePx;

        return $this;
    }

    public function isInsightFaceGenderCheck(): bool
    {
        return $this->insightFaceGenderCheck;
    }

    public function setInsightFaceGenderCheck(bool $insightFaceGenderCheck): static
    {
        $this->insightFaceGenderCheck = $insightFaceGenderCheck;

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

        if (
            $this->insightFaceCandidateThreshold > min(
                $this->insightFaceVideoLinkThreshold,
                $this->insightFaceSuggestionThreshold,
                $this->insightFaceMatchThreshold,
            )
        ) {
            $context
                ->buildViolation(
                    'Muss unter allen anderen Schwellen liegen, sonst werden die nötigen Kandidaten gar nicht gespeichert.',
                )
                ->atPath('insightFaceCandidateThreshold')
                ->addViolation();
        }
    }
}
