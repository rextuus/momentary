<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Entity\User;
use App\Entity\UserGroup;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;

#[ORM\Entity(repositoryClass: VideoRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['video:list']]
        ),
        new Get(
            normalizationContext: ['groups' => ['video:detail']]
        )
    ]
)]
// Erlaubt: /api/videos?title=mallorca
#[ApiFilter(SearchFilter::class, properties: ['title' => 'partial'])]
// Erlaubt: /api/videos?videoFaces.person.name=Wolf
#[ApiFilter(SearchFilter::class, properties: ['videoFaces.person.name' => 'partial'])]
#[ApiFilter(SearchFilter::class, properties: ['scenes.tags.name' => 'partial', 'scenes.tags.id' => 'exact'])]
class Video
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['video:list', 'video:detail'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['video:list', 'video:detail'])]
    private ?string $title = null;


    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['video:detail'])]
    private ?string $sourceFile = null;

    #[ORM\Column]
    #[Groups(['video:list', 'video:detail'])]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * @var Collection<int, VideoFace>
     */
    #[ORM\OneToMany(targetEntity: VideoFace::class, mappedBy: 'video', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['video:detail'])]
    private Collection $videoFaces;

    #[ORM\Column(type: 'string', length: 32, enumType: VideoStatus::class)]
    #[Groups(['video:list', 'video:detail'])]
    private VideoStatus $status = VideoStatus::PENDING;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?float $analysisFps = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?float $minSceneLengthForRefinement = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?float $refinedAnalysisFps = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['video:detail'])]
    private bool $mergeEmptyScenesWithLastPersonScene = false;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['video:list', 'video:detail'])] // Fehler wollen wir oft auch in der Liste sehen
    private ?string $errorMessage = null;

    // Pfad zur lokalen Datei nach dem Download
    #[ORM\Column(length: 1000, nullable: true)]
    #[Groups(['video:detail'])]
    private ?string $localPath = null;

    #[ORM\Column(length: 1000, nullable: true)]
    #[Groups(['video:detail'])]
    private ?string $convertedVideoPath = null;

    /**
     * @var Collection<int, VideoScene>
     */
    #[ORM\OneToMany(targetEntity: VideoScene::class, mappedBy: 'video', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sceneNumber' => 'ASC'])]
    #[Groups(['video:detail'])]
    private Collection $scenes;

    #[ORM\Column(length: 1000, nullable: true)]
    #[Groups(['video:detail'])]
    private ?string $thumbnailPath = null;

    /**
     * @var Collection<int, VideoChapter>
     */
    #[ORM\OneToMany(targetEntity: VideoChapter::class, mappedBy: 'video', cascade: ['remove'], orphanRemoval: true)]
    #[Groups(['video:detail'])]
    private Collection $chapters;

    /**
     * @var Collection<int, VideoProcessingStep>
     */
    #[ORM\OneToMany(targetEntity: VideoProcessingStep::class, mappedBy: 'video', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    #[Groups(['video:detail'])]
    private Collection $processingSteps;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['video:list', 'video:detail'])]
    private int $totalFrames = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['video:list', 'video:detail'])]
    private int $processedFrames = 0;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:list', 'video:detail'])]
    private ?float $duration = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['video:detail'])]
    private ?string $currentFrameDirectory = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['video:detail'])]
    private ?string $currentRefinementFrameDirectory = null;


    #[ORM\Column(length: 511, nullable: true)]
    #[Groups(['video:detail'])]
    private ?string $jellyfinPath = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['video:list', 'video:detail'])]
    private ?string $jellyfinItemId = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['video:list', 'video:detail'])]
    private ?string $directoryHash = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $owner = null;

    #[ORM\Column(options: ["default" => false])]
    #[Groups(['video:list', 'video:detail'])]
    private bool $isPublic = false;

    /**
     * @var Collection<int, UserGroup>
     */
    #[ORM\ManyToMany(targetEntity: UserGroup::class)]
    private Collection $allowedGroups;

    /**
     * @var Collection<int, Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'video_tags')]
    #[Groups(['video:detail'])]
    private Collection $tags;

    public function __construct()
    {
        $this->videoFaces = new ArrayCollection();
        $this->scenes = new ArrayCollection();
        $this->chapters = new ArrayCollection();
        $this->processingSteps = new ArrayCollection();
        $this->allowedGroups = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): static
    {
        $this->isPublic = $isPublic;
        return $this;
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

    public function getConvertedVideoPath(): ?string
    {
        return $this->convertedVideoPath;
    }

    public function setConvertedVideoPath(?string $convertedVideoPath): self
    {
        $this->convertedVideoPath = $convertedVideoPath;
        return $this;
    }

    public function getThumbnailPath(): ?string
    {
        return $this->thumbnailPath;
    }

    public function setThumbnailPath(?string $thumbnailPath): self
    {
        $this->thumbnailPath = $thumbnailPath;

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

    public function getAnalysisFps(): ?float
    {
        return $this->analysisFps;
    }

    public function setAnalysisFps(?float $analysisFps): self
    {
        $this->analysisFps = $analysisFps;
        return $this;
    }

    public function getMinSceneLengthForRefinement(): ?float
    {
        return $this->minSceneLengthForRefinement;
    }

    public function setMinSceneLengthForRefinement(?float $minSceneLengthForRefinement): self
    {
        $this->minSceneLengthForRefinement = $minSceneLengthForRefinement;
        return $this;
    }

    public function getRefinedAnalysisFps(): ?float
    {
        return $this->refinedAnalysisFps;
    }

    public function setRefinedAnalysisFps(?float $refinedAnalysisFps): self
    {
        $this->refinedAnalysisFps = $refinedAnalysisFps;
        return $this;
    }

    public function isMergeEmptyScenesWithLastPersonScene(): bool
    {
        return $this->mergeEmptyScenesWithLastPersonScene;
    }

    public function setMergeEmptyScenesWithLastPersonScene(bool $mergeEmptyScenesWithLastPersonScene): self
    {
        $this->mergeEmptyScenesWithLastPersonScene = $mergeEmptyScenesWithLastPersonScene;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getTotalFrames(): int
    {
        return $this->totalFrames;
    }

    public function setTotalFrames(int $totalFrames): self
    {
        $this->totalFrames = $totalFrames;
        return $this;
    }

    public function getProcessedFrames(): int
    {
        return $this->processedFrames;
    }

    public function setProcessedFrames(int $processedFrames): self
    {
        $this->processedFrames = $processedFrames;
        return $this;
    }

    public function getDuration(): ?float
    {
        return $this->duration;
    }

    public function setDuration(?float $duration): self
    {
        $this->duration = $duration;
        return $this;
    }

    public function getCurrentFrameDirectory(): ?string
    {
        return $this->currentFrameDirectory;
    }

    public function setCurrentFrameDirectory(?string $currentFrameDirectory): self
    {
        $this->currentFrameDirectory = $currentFrameDirectory;
        return $this;
    }

    public function getCurrentRefinementFrameDirectory(): ?string
    {
        return $this->currentRefinementFrameDirectory;
    }

    public function setCurrentRefinementFrameDirectory(?string $currentRefinementFrameDirectory): self
    {
        $this->currentRefinementFrameDirectory = $currentRefinementFrameDirectory;
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

    public function getJellyfinPath(): ?string
    {
        return $this->jellyfinPath;
    }

    public function setJellyfinPath(?string $jellyfinPath): self
    {
        $this->jellyfinPath = $jellyfinPath;

        return $this;
    }

    public function getJellyfinItemId(): ?string
    {
        return $this->jellyfinItemId;
    }

    public function setJellyfinItemId(?string $jellyfinItemId): self
    {
        $this->jellyfinItemId = $jellyfinItemId;
        return $this;
    }

    public function getDirectoryHash(): ?string
    {
        return $this->directoryHash;
    }

    public function setDirectoryHash(?string $directoryHash): self
    {
        $this->directoryHash = $directoryHash;
        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;
        return $this;
    }

    /**
     * @return Collection<int, UserGroup>
     */
    public function getAllowedGroups(): Collection
    {
        return $this->allowedGroups;
    }

    public function addAllowedGroup(UserGroup $group): static
    {
        if (!$this->allowedGroups->contains($group)) {
            $this->allowedGroups->add($group);
        }
        return $this;
    }

    public function removeAllowedGroup(UserGroup $group): static
    {
        $this->allowedGroups->removeElement($group);
        return $this;
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

    /**
     * @return Collection<int, VideoChapter>
     */
    public function getChapters(): Collection
    {
        return $this->chapters;
    }

    public function addChapter(VideoChapter $chapter): static
    {
        if (!$this->chapters->contains($chapter)) {
            $this->chapters->add($chapter);
            $chapter->setVideo($this);
        }
        return $this;
    }

    public function removeChapter(VideoChapter $chapter): static
    {
        if ($this->chapters->removeElement($chapter)) {
            if ($chapter->getVideo() === $this) {
                $chapter->setVideo(null);
            }
        }
        return $this;
    }

    #[Groups(['video:list', 'video:detail'])]
    public function getThumbnailUrl(): ?string
    {
        return $this->thumbnailPath ?? 'defaults/video-placeholder.jpg';
    }

    /**
     * @return Collection<int, VideoProcessingStep>
     */
    public function getProcessingSteps(): Collection
    {
        return $this->processingSteps;
    }

    public function addProcessingStep(VideoProcessingStep $processingStep): static
    {
        if (!$this->processingSteps->contains($processingStep)) {
            $this->processingSteps->add($processingStep);
            $processingStep->setVideo($this);
        }

        return $this;
    }

    public function removeProcessingStep(VideoProcessingStep $processingStep): static
    {
        if ($this->processingSteps->removeElement($processingStep)) {
            // set the owning side to null (unless already changed)
            if ($processingStep->getVideo() === $this) {
                $processingStep->setVideo(null);
            }
        }

        return $this;
    }

    public function getProcessingStepFinishedAt(VideoStatus $status): ?\DateTimeImmutable
    {
        foreach ($this->processingSteps as $step) {
            if ($step->getStep() === $status) {
                return $step->getFinishedAt();
            }
        }

        return null;
    }

    public function getProcessingStepStartedAt(VideoStatus $status): ?\DateTimeImmutable
    {
        foreach ($this->processingSteps as $step) {
            if ($step->getStep() === $status) {
                return $step->getStartedAt();
            }
        }

        return null;
    }
}