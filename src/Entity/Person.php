<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: PersonRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['person:list']]
        ),
        new Get(
            normalizationContext: ['groups' => ['person:detail']]
        )
    ]
)]
class Person
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['person:list', 'person:detail'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['person:list', 'person:detail'])]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['person:detail'])]
    private ?string $description = null;

    /**
     * @var Collection<int, VideoFace>
     */
    #[ORM\OneToMany(targetEntity: VideoFace::class, mappedBy: 'person', cascade: ['remove'], orphanRemoval: true)]
    private Collection $videoFaces;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    #[Groups(['person:list', 'person:detail'])]
    private bool $identified = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    #[Groups(['person:list', 'person:detail'])]
    private bool $wasted = false;

    /**
     * @var Collection<int, VideoFace>
     */
    #[ORM\OneToMany(targetEntity: VideoFace::class, mappedBy: 'detection')]
    private Collection $detectionFaces;

    #[ORM\Column(type: 'string', enumType: PersonStatus::class, options: ['default' => PersonStatus::NEW->value])]
    #[Groups(['person:list', 'person:detail'])]
    private PersonStatus $status = PersonStatus::NEW;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['person:list', 'person:detail'])]
    private int $showCount = 0;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'merged_into_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['person:detail'])]
    private ?Person $mergedInto = null;

    #[ORM\ManyToOne(targetEntity: VideoFace::class)]
    private ?VideoFace $profileFace = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['person:list', 'person:detail'])]
    private ?int $age = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['person:list', 'person:detail'])]
    private ?string $gender = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['person:detail'])]
    private ?string $characteristics = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['person:list', 'person:detail'])]
    private ?string $fullName = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['person:list', 'person:detail'])]
    private ?string $relation = null;

    public function __construct()
    {
        $this->videoFaces = new ArrayCollection();
        $this->detectionFaces = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProfileFace(): ?VideoFace
    {
        return $this->profileFace;
    }

    public function setProfileFace(?VideoFace $face): self
    {
        $this->profileFace = $face;
        return $this;
    }

    public function getAge(): ?int
    {
        return $this->age;
    }

    public function setAge(?int $age): self
    {
        $this->age = $age;
        return $this;
    }

    public function getGender(): ?string
    {
        return $this->gender;
    }

    public function setGender(?string $gender): self
    {
        $this->gender = $gender;
        return $this;
    }

    public function getCharacteristics(): ?string
    {
        return $this->characteristics;
    }

    public function setCharacteristics(?string $characteristics): self
    {
        $this->characteristics = $characteristics;
        return $this;
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(?string $fullName): self
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getRelation(): ?string
    {
        return $this->relation;
    }

    public function setRelation(?string $relation): self
    {
        $this->relation = $relation;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return Collection<int, VideoFace>
     */
    public function getVideoFaces(): Collection
    {
        return $this->videoFaces;
    }

    public function addVideoFace(VideoFace $videoFace): static
    {
        if (!$this->videoFaces->contains($videoFace)) {
            $this->videoFaces->add($videoFace);
            $videoFace->setPerson($this);
        }

        return $this;
    }

    public function removeVideoFace(VideoFace $videoFace): static
    {
        if ($this->videoFaces->removeElement($videoFace)) {
            // set the owning side to null (unless already changed)
            if ($videoFace->getPerson() === $this) {
                $videoFace->setPerson(null);
            }
        }

        return $this;
    }

    public function isIdentified(): ?bool
    {
        return $this->identified;
    }

    public function setIdentified(bool $identified): static
    {
        $this->identified = $identified;

        return $this;
    }

    public function getProbablyGender(): ?string
    {
        $gender = null;
        $videoFaces = $this->getVideoFaces();
        $count = 0;
        while ($gender === null && $count < $videoFaces->count()) {
            $gender = $this->determineGenderFromDbField($videoFaces->get($count)->getGender());
            $count++;
        }

        if ($gender === null) {
            return 'unknown';
        }

        return $gender;
    }

    public function determineGenderFromDbField(string $input): ?string
    {
        if (trim(strtolower($input)) === 'unknown' || empty($input)) {
            return null;
        }

        // Wenn der String direkt "Male" oder "Female" ist (AWS Format)
        if (in_array(ucfirst(strtolower($input)), ['Male', 'Female'])) {
            return ucfirst(strtolower($input));
        }

        // Altes Python-Format (JSON) Fallback
        $parsed = json_decode($input, true);

        // Check if JSON decoding failed
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
            return null; // Return null for invalid input
        }

        // Determine the gender based on the largest confidence value
        $maxGender = null;
        $maxValue = -1;

        foreach ($parsed as $gender => $value) {
            if ($value > $maxValue) {
                $maxGender = $gender;
                $maxValue = $value;
            }
        }

        return $maxGender; // Return the gender with the highest confidence
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }

    public function isWasted(): ?bool
    {
        return $this->wasted;
    }

    public function setWasted(bool $wasted): static
    {
        $this->wasted = $wasted;

        return $this;
    }

    /**
     * @return Collection<int, VideoFace>
     */
    public function getDetectionFaces(): Collection
    {
        return $this->detectionFaces;
    }

    public function addDetectionFace(VideoFace $detectionFace): static
    {
        if (!$this->detectionFaces->contains($detectionFace)) {
            $this->detectionFaces->add($detectionFace);
            $detectionFace->setDetection($this);
        }

        return $this;
    }

    public function removeDetectionFace(VideoFace $detectionFace): static
    {
        if ($this->detectionFaces->removeElement($detectionFace)) {
            // set the owning side to null (unless already changed)
            if ($detectionFace->getDetection() === $this) {
                $detectionFace->setDetection(null);
            }
        }

        return $this;
    }

    public function getDetectionFace(): VideoFace
    {
        $face = $this->detectionFaces->first();
        if (!$face) {
            dd($this->name);
            throw new \Exception('No detection face set');
        }

        return $this->detectionFaces->first();
    }

    public function getStatus(): PersonStatus
    {
        return $this->status;
    }

    public function setStatus(PersonStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getSceneCount(): int
    {
        $sceneIds = [];
        foreach ($this->videoFaces as $face) {
            if ($face->getVideoScene()) {
                $sceneIds[] = $face->getVideoScene()->getId();
            }
        }

        return count(array_unique($sceneIds));
    }

    public function getShowCount(): int
    {
        return $this->showCount;
    }

    public function setShowCount(int $showCount): self
    {
        $this->showCount = $showCount;
        return $this;
    }

    public function getMergedInto(): ?Person
    {
        return $this->mergedInto;
    }

    public function setMergedInto(?Person $mergedInto): self
    {
        $this->mergedInto = $mergedInto;
        return $this;
    }

    #[Groups(['person:list', 'person:detail'])]
    public function getProfileImageUrl(): ?string
    {
        if (!$this->profileFace) {
            return null;
        }

        return $this->profileFace->getFaceImagePath();
    }
}
