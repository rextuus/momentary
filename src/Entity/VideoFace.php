<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\VideoFaceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: VideoFaceRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['videoface:list']]
        ),
        new Get(
            normalizationContext: ['groups' => ['videoface:detail']]
        )
    ]
)]
class VideoFace
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['video:list', 'video:detail', 'videoface:list', 'videoface:detail'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'videoFaces')]
    #[Groups(['videoface:detail'])]
    private ?Video $video = null;

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

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $faceImagePath = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['video:detail', 'videoface:detail'])]
    private ?array $boundingBox = null;

    #[ORM\Column(nullable: true)]
    private ?array $embedding = null;

    #[ORM\Column]
    #[Groups(['video:list', 'video:detail', 'videoface:list', 'videoface:detail'])]
    private ?int $age = null;

    #[ORM\Column(length: 255)]
    #[Groups(['video:list', 'video:detail', 'videoface:list', 'videoface:detail'])]
    private ?string $gender = null;

    #[ORM\Column(length: 255)]
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

    public function __construct()
    {
        $this->matchFor = new ArrayCollection();
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

    public function getFaceImagePath(): ?string
    {
        return $this->faceImagePath;
    }

    public function setFaceImagePath(?string $faceImagePath): static
    {
        $this->faceImagePath = $faceImagePath;
        return $this;
    }

    public function getBoundingBox(): ?array
    {
        return $this->boundingBox;
    }

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
            ($this->boundingBox['Height'] ?? 0) * 100
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

    public function setEmotion(string $emotion): static
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

    public function getEmbedding(): ?array
    {
        return $this->embedding;
    }

    public function setEmbedding(?array $embedding): self
    {
        $this->embedding = $embedding;
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

    public function getMatchFor(): Collection
    {
        return $this->matchFor;
    }

    #[Groups(['video:list', 'video:detail', 'videoface:list', 'videoface:detail'])]
    public function getImageUrl(): ?string
    {
        return $this->faceImagePath;
    }
}