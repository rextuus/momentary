<?php

namespace App\Entity;

use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VideoRepository::class)]
class Video
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $youtubeUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceFile = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * @var Collection<int, VideoFace>
     */
    #[ORM\OneToMany(targetEntity: VideoFace::class, mappedBy: 'video')]
    private Collection $videoFaces;

    #[ORM\Column(type: 'string', length: 32, enumType: VideoStatus::class)]
    private VideoStatus $status = VideoStatus::PENDING;

    // Pfad zur lokalen Datei nach dem Download
    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $localPath = null;

    /**
     * @var Collection<int, VideoScene>
     */
    #[ORM\OneToMany(targetEntity: VideoScene::class, mappedBy: 'video', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sceneNumber' => 'ASC'])]
    private Collection $scenes;

    public function __construct()
    {
        $this->videoFaces = new ArrayCollection();
        $this->scenes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getYoutubeUrl(): ?string
    {
        return $this->youtubeUrl;
    }

    public function setYoutubeUrl(?string $youtubeUrl): static
    {
        $this->youtubeUrl = $youtubeUrl;

        return $this;
    }

    public function getSourceFile(): ?string
    {
        return $this->sourceFile;
    }

    public function setSourceFile(?string $sourceFile): self
    {
        $this->sourceFile = $sourceFile;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

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
            $videoFace->setVideo($this);
        }

        return $this;
    }

    public function removeVideoFace(VideoFace $videoFace): static
    {
        if ($this->videoFaces->removeElement($videoFace)) {
            // set the owning side to null (unless already changed)
            if ($videoFace->getVideo() === $this) {
                $videoFace->setVideo(null);
            }
        }

        return $this;
    }

    public function getStatus(): VideoStatus
    {
        return $this->status;
    }

    public function setStatus(VideoStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getLocalPath(): ?string
    {
        return $this->localPath;
    }

    public function setLocalPath(?string $localPath): self
    {
        $this->localPath = $localPath;
        return $this;
    }

    /**
     * @return Collection<int, VideoScene>
     */
    public function getScenes(): Collection
    {
        return $this->scenes;
    }

    public function addScene(VideoScene $scene): static
    {
        if (!$this->scenes->contains($scene)) {
            $this->scenes->add($scene);
            $scene->setVideo($this);
        }
        return $this;
    }

    public function removeScene(VideoScene $scene): static
    {
        if ($this->scenes->removeElement($scene)) {
            if ($scene->getVideo() === $this) {
                $scene->setVideo(null);
            }
        }
        return $this;
    }
}
