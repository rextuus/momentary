<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\VideoFaceRepository;
use App\Service\Face\Embedding\EmbeddingMath;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: VideoFaceRepository::class)]
#[ORM\Index(name: 'idx_video_face_embedding_model', columns: ['embedding_model'])]
#[ApiResource(operations: [
    new GetCollection(normalizationContext: ['groups' => ['videoface:list']]),
    new Get(normalizationContext: ['groups' => ['videoface:detail']]),
])]
class VideoFace
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['video:list', 'video:detail', 'videoface:list', 'videoface:detail'])]
    private ?int $id = null;

    /** Video oder Foto, in dem das Gesicht erkannt wurde */
    #[ORM\ManyToOne(targetEntity: Medium::class, inversedBy: 'faces')]
    #[ORM\JoinColumn(name: 'medium_id', nullable: true, onDelete: 'CASCADE')]
    private ?Medium $medium = null;

    #[ORM\ManyToOne(inversedBy: 'videoFaces')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['video:list', 'video:detail', 'videoface:list', 'videoface:detail'])]
    private ?Person $person = null;

    /**
     * Verknüpfung zur Szene
     */
    #[ORM\ManyToOne(targetEntity: VideoScene::class, inversedBy: 'videoFaces')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['videoface:detail'])]
    private ?VideoScene $videoScene = null;

    #[ORM\Column]
    #[Groups(['video:list', 'video:detail', 'videoface:list', 'videoface:detail'])]
    private ?int $timestamp = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['video:list', 'video:detail', 'videoface:list', 'videoface:detail'])]
    private ?string $faceLabel = null;

    #[ORM\ManyToOne(targetEntity: File::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?File $faceImage = null;

    /** @var array<string, int|float>|null Bounding Box aus Rekognition (Left/Top/Width/Height, relativ) */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['video:detail', 'videoface:detail'])]
    private ?array $boundingBox = null;

    /** Anbieter, der das Gesicht erkannt hat (rekognition, insightface); faceLabel ist nur dort eindeutig. */
    #[ORM\Column(length: 20, options: ['default' => 'rekognition'])]
    private string $provider = 'rekognition';

    /**
     * InsightFace: Embedding als float32 (2 KB), siehe EmbeddingMath. Doctrine liefert BLOBs als Stream.
     *
     * @var string|resource|null
     */
    #[ORM\Column(type: Types::BLOB, length: 65535, nullable: true)]
    private mixed $embeddingData = null;

    /** Erkennungsmodell des Embeddings; Embeddings verschiedener Modelle sind nicht vergleichbar. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $embeddingModel = null;

    /** Sicherheit der Detektion (0–100). */
    #[ORM\Column(nullable: true)]
    private ?float $detectionScore = null;

    /** InsightFace: kürzere Seite des Gesichts in Pixeln des analysierten Bildes (Referenzauswahl, EmbeddingIndex). */
    #[ORM\Column(nullable: true)]
    private ?int $faceSizePx = null;

    /**
     * Ähnliche, bereits indizierte Gesichter laut Rekognition: FaceId => Ähnlichkeit.
     * Grundlage für die Konsolidierung innerhalb eines Videos, ohne AWS erneut zu fragen.
     *
     * @var array<string, float>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $matchCandidates = null;

    #[ORM\Column]
    #[Groups(['video:list', 'video:detail', 'videoface:list', 'videoface:detail'])]
    private ?int $age = null;

    #[ORM\Column(length: 255)]
    #[Groups(['video:list', 'video:detail', 'videoface:list', 'videoface:detail'])]
    private ?string $gender = null;

    /** Nur Rekognition; InsightFace schätzt keine Emotion. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['video:list', 'video:detail', 'videoface:list', 'videoface:detail'])]
    private ?string $emotion = null;

    #[ORM\ManyToOne(inversedBy: 'detectionFaces')]
    private ?Person $detection = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'matchFor')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?self $matchedBy = null;

    /**
     * @var Collection<int, self>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'matchedBy')]
    private Collection $matchFor;

    #[ORM\Column(nullable: true)]
    #[Groups(['videoface:detail'])]
    private ?float $matchSimilarity = null;

    #[ORM\Column]
    #[Groups(['video:list', 'video:detail', 'videoface:list', 'videoface:detail'])]
    private bool $isVerified = false;

    public function __construct()
    {
        $this->matchFor = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMedium(): ?Medium
    {
        return $this->medium;
    }

    public function setMedium(?Medium $medium): static
    {
        $this->medium = $medium;

        return $this;
    }

    /**
     * Das Video, falls das Gesicht aus einem Video stammt (bei Fotos null).
     */
    #[Groups(['videoface:detail'])]
    public function getVideo(): ?Video
    {
        return $this->medium instanceof Video ? $this->medium : null;
    }

    public function setVideo(?Video $video): static
    {
        return $this->setMedium($video);
    }

    public function getPhoto(): ?Photo
    {
        return $this->medium instanceof Photo ? $this->medium : null;
    }

    public function getPerson(): ?Person
    {
        return $this->person;
    }

    public function setPerson(?Person $person): static
    {
        $this->person = $person;
        return $this;
    }

    public function getVideoScene(): ?VideoScene
    {
        return $this->videoScene;
    }

    public function setVideoScene(?VideoScene $videoScene): static
    {
        $this->videoScene = $videoScene;
        return $this;
    }

    public function getTimestamp(): ?int
    {
        return $this->timestamp;
    }

    public function setTimestamp(int $timestamp): static
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    public function getFaceLabel(): ?string
    {
        return $this->faceLabel;
    }

    public function setFaceLabel(?string $faceLabel): static
    {
        $this->faceLabel = $faceLabel;
        return $this;
    }

    public function getFaceImage(): ?File
    {
        return $this->faceImage;
    }

    public function setFaceImage(?File $faceImage): static
    {
        $this->faceImage = $faceImage;
        return $this;
    }

    public function getFaceImagePath(): ?string
    {
        return $this->faceImage?->getRelativePath();
    }

    /**
     * @return array<string, int|float>|null
     */
    public function getBoundingBox(): ?array
    {
        return $this->boundingBox;
    }

    /**
     * @param array<string, int|float>|null $boundingBox
     */
    public function setBoundingBox(?array $boundingBox): static
    {
        $this->boundingBox = $boundingBox;
        return $this;
    }

    public function getBoundingBoxStyles(): string
    {
        if (!$this->boundingBox) {
            return '';
        }
        return sprintf(
            'top: %f%%; left: %f%%; width: %f%%; height: %f%%;',
            ($this->boundingBox['Top'] ?? 0) * 100,
            ($this->boundingBox['Left'] ?? 0) * 100,
            ($this->boundingBox['Width'] ?? 0) * 100,
            ($this->boundingBox['Height'] ?? 0) * 100,
        );
    }

    public function getAge(): ?int
    {
        return $this->age;
    }

    public function setAge(int $age): static
    {
        $this->age = $age;
        return $this;
    }

    public function getGender(): ?string
    {
        return $this->gender;
    }

    public function setGender(string $gender): static
    {
        $this->gender = $gender;
        return $this;
    }

    public function getEmotion(): ?string
    {
        return $this->emotion;
    }

    public function setEmotion(?string $emotion): static
    {
        $this->emotion = $emotion;
        return $this;
    }

    public function getMatchedBy(): ?self
    {
        return $this->matchedBy;
    }

    public function setMatchedBy(?self $matchedBy): static
    {
        $this->matchedBy = $matchedBy;
        return $this;
    }

    public function getMatchSimilarity(): ?float
    {
        return $this->matchSimilarity;
    }

    public function setMatchSimilarity(?float $matchSimilarity): static
    {
        $this->matchSimilarity = $matchSimilarity;
        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;
        return $this;
    }

    /**
     * @return array<string, float>
     */
    public function getMatchCandidates(): array
    {
        return $this->matchCandidates ?? [];
    }

    /**
     * @param array<string, float> $matchCandidates
     */
    public function setMatchCandidates(array $matchCandidates): static
    {
        $this->matchCandidates = $matchCandidates;

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * @return list<float>|null
     */
    public function getEmbedding(): ?array
    {
        $data = $this->embeddingData;
        if (\is_resource($data)) {
            $data = stream_get_contents($data, offset: 0);
            $this->embeddingData = $data === false ? null : $data;
            $data = $this->embeddingData;
        }

        return \is_string($data) && $data !== '' ? EmbeddingMath::unpack($data) : null;
    }

    /**
     * @param list<float>|null $embedding
     */
    public function setEmbedding(?array $embedding, ?string $model = null): static
    {
        $this->embeddingData = $embedding === null ? null : EmbeddingMath::pack($embedding);
        $this->embeddingModel = $embedding === null ? null : $model;

        return $this;
    }

    public function getEmbeddingModel(): ?string
    {
        return $this->embeddingModel;
    }

    public function getDetectionScore(): ?float
    {
        return $this->detectionScore;
    }

    public function setDetectionScore(?float $detectionScore): static
    {
        $this->detectionScore = $detectionScore;

        return $this;
    }

    public function getFaceSizePx(): ?int
    {
        return $this->faceSizePx;
    }

    public function setFaceSizePx(?int $faceSizePx): static
    {
        $this->faceSizePx = $faceSizePx;

        return $this;
    }

    public function getDetection(): ?Person
    {
        return $this->detection;
    }

    public function setDetection(?Person $detection): self
    {
        $this->detection = $detection;
        return $this;
    }

    /**
     * @return Collection<int, VideoFace>
     */
    public function getMatchFor(): Collection
    {
        return $this->matchFor;
    }

    #[Groups(['video:list', 'video:detail', 'videoface:list', 'videoface:detail'])]
    public function getImageUrl(): ?string
    {
        return $this->faceImage ? $this->faceImage->getRelativePath() : null;
    }

    public function addMatchFor(VideoFace $matchFor): static
    {
        if (!$this->matchFor->contains($matchFor)) {
            $this->matchFor->add($matchFor);
            $matchFor->setMatchedBy($this);
        }

        return $this;
    }

    public function removeMatchFor(VideoFace $matchFor): static
    {
        if ($this->matchFor->removeElement($matchFor)) {
            // set the owning side to null (unless already changed)
            if ($matchFor->getMatchedBy() === $this) {
                $matchFor->setMatchedBy(null);
            }
        }

        return $this;
    }
}
