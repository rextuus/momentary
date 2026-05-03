<?php

namespace App\Entity;

use App\Repository\PersonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PersonRepository::class)]
class Person
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * @var Collection<int, VideoFace>
     */
    #[ORM\OneToMany(targetEntity: VideoFace::class, mappedBy: 'person')]
    private Collection $videoFaces;

    #[ORM\Column(type: Types::BOOLEAN, options: [ 'default' => false])]
    private bool $identified = false;

    #[ORM\Column(type: Types::BOOLEAN, options: [ 'default' => false])]
    private bool $wasted = false;

    /**
     * @var Collection<int, VideoFace>
     */
    #[ORM\OneToMany(targetEntity: VideoFace::class, mappedBy: 'detection')]
    private Collection $detectionFaces;

    public function __construct()
    {
        $this->videoFaces = new ArrayCollection();
        $this->detectionFaces = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    function determineGenderFromDbField(string $input): ?string
    {
        // Handle 'unknown' case
        if (trim(strtolower($input)) === 'unknown') {
            return null;
        }

        // Try to convert the string to valid JSON format
        // Use regex to replace single quotes with double quotes and remove 'np.float32'
        $jsonString = preg_replace([
            "/'/",                    // Replace single quotes with double quotes
            "/np\.float32\((.*?)\)/", // Remove np.float32(...) and only use the number inside
        ], [
            '"',                     // Replacement for single quotes
            '$1'                     // Keep the inner number from np.float32(...)
        ], $input);

        // Decode potential JSON to an associative array
        $parsed = json_decode($jsonString, true);

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
}
