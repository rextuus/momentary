<?php

namespace App\Entity;

use App\Repository\VideoSceneRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VideoSceneRepository::class)]
class VideoScene
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Video::class, inversedBy: 'scenes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Video $video = null;

    #[ORM\Column]
    private int $sceneNumber;

    #[ORM\Column]
    private float $startSeconds;

    #[ORM\Column]
    private float $endSeconds;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    /**
     * @var Collection<int, VideoFace>
     */
    #[ORM\OneToMany(targetEntity: VideoFace::class, mappedBy: 'videoScene')]
    private Collection $videoFaces;

    /**
     * @var Collection<int, Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'scenes')]
    private Collection $tags;

    public function __construct()
    {
        $this->videoFaces = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }

    /**
     * @return Collection<int, Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        $this->tags->removeElement($tag);

        return $this;
    }

    public function getVideoFaces(): Collection
    {
        return $this->videoFaces;
    }

    public function setVideoFaces(Collection $videoFaces): self
    {
        $this->videoFaces = $videoFaces;
        return $this;
    }



    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVideo(): ?Video
    {
        return $this->video;
    }

    public function setVideo(?Video $video): self
    {
        $this->video = $video;
        return $this;
    }

    public function getSceneNumber(): int
    {
        return $this->sceneNumber;
    }

    public function setSceneNumber(int $sceneNumber): self
    {
        $this->sceneNumber = $sceneNumber;
        return $this;
    }

    public function getStartSeconds(): float
    {
        return $this->startSeconds;
    }

    public function setStartSeconds(float $startSeconds): self
    {
        $this->startSeconds = $startSeconds;
        return $this;
    }

    public function getEndSeconds(): float
    {
        return $this->endSeconds;
    }

    public function setEndSeconds(float $endSeconds): self
    {
        $this->endSeconds = $endSeconds;
        return $this;
    }
}
