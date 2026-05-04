<?php

namespace App\Entity;

use App\Repository\VideoFaceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VideoFaceRepository::class)]
class VideoFace
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'videoFaces')]
    private ?Video $video = null;

    #[ORM\ManyToOne(inversedBy: 'videoFaces')]
    #[ORM\JoinColumn(nullable: false)] // Falls jedes Gesicht zwingend eine Person braucht
    private ?Person $person = null;

    #[ORM\Column]
    private ?int $timestamp = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $faceLabel = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $faceImagePath = null;

    #[ORM\Column(nullable: true)]
    private ?array $embedding = null;

    #[ORM\Column]
    private ?int $age = null;

    #[ORM\Column(length: 255)]
    private ?string $gender = null;

    #[ORM\Column(length: 255)]
    private ?string $emotion = null;

    #[ORM\ManyToOne(inversedBy: 'detectionFaces')]
    private ?Person $detection = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'matchFor')]
    private ?self $matchedBy = null;

    /**
     * @var Collection<int, self>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'matchedBy')]
    private Collection $matchFor;

    #[ORM\Column(nullable: true)]
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

    public function getEmbedding(): ?array
    {
        return $this->embedding;
    }

    public function setEmbedding(?array $embedding): static
    {
        $this->embedding = $embedding;

        return $this;
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

    public function getDetection(): ?Person
    {
        return $this->detection;
    }

    public function setDetection(?Person $detection): static
    {
        $this->detection = $detection;

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

    /**
     * @return Collection<int, self>
     */
    public function getMatchFor(): Collection
    {
        return $this->matchFor;
    }

    public function addMatchFor(self $matchFor): static
    {
        if (!$this->matchFor->contains($matchFor)) {
            $this->matchFor->add($matchFor);
            $matchFor->setMatchedBy($this);
        }

        return $this;
    }

    public function removeMatchFor(self $matchFor): static
    {
        if ($this->matchFor->removeElement($matchFor)) {
            // set the owning side to null (unless already changed)
            if ($matchFor->getMatchedBy() === $this) {
                $matchFor->setMatchedBy(null);
            }
        }

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
}
