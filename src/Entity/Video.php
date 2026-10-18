<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\ExactFilter;
use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: VideoRepository::class)]
#[ApiResource(operations: [
    new GetCollection(normalizationContext: ['groups' => ['video:list']], parameters: [
        'title' => new QueryParameter(filter: new PartialSearchFilter(), property: 'title'),
        'videoFaces.person.name' => new QueryParameter(
            filter: new PartialSearchFilter(),
            property: 'faces.person.name',
        ),
        'scenes.tags.name' => new QueryParameter(
            filter: new PartialSearchFilter(),
            property: 'scenes.sceneTags.tag.name',
        ),
        'scenes.tags.id' => new QueryParameter(filter: new ExactFilter(), property: 'scenes.sceneTags.tag.id'),
    ]),
    new Get(normalizationContext: ['groups' => ['video:detail']]),
])]
class Video extends Medium
{
    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['video:detail'])]
    private ?string $fileHash = null;

    #[ORM\ManyToOne(targetEntity: File::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['video:detail'])]
    private ?File $sourceFile = null;

    #[ORM\ManyToOne(targetEntity: File::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['video:detail'])]
    private ?File $convertedFile = null;

    #[ORM\ManyToOne(targetEntity: File::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['video:detail'])]
    private ?File $thumbnailFile = null;

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
    #[Groups(['video:list', 'video:detail'])]
    private ?string $errorMessage = null;

    /**
     * @var Collection<int, VideoScene>
     */
    #[ORM\OneToMany(
        targetEntity: VideoScene::class,
        mappedBy: 'video',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['sceneNumber' => \SortDirection::Ascending])]
    #[Groups(['video:detail'])]
    private Collection $scenes;

    #[ORM\OneToOne(targetEntity: VideoTranscript::class, mappedBy: 'video', cascade: ['remove'])]
    private ?VideoTranscript $transcript = null;

    /**
     * @var Collection<int, VideoChapter>
     */
    #[ORM\OneToMany(targetEntity: VideoChapter::class, mappedBy: 'video', cascade: ['remove'], orphanRemoval: true)]
    #[Groups(['video:detail'])]
    private Collection $chapters;

    /**
     * @var Collection<int, VideoProcessingStep>
     */
    #[ORM\OneToMany(
        targetEntity: VideoProcessingStep::class,
        mappedBy: 'video',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['createdAt' => \SortDirection::Ascending])]
    #[Groups(['video:detail'])]
    private Collection $processingSteps;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['video:list', 'video:detail'])]
    private int $totalFrames = 0;

    /** Schritt, auf den sich der Fortschrittszähler bezieht (VideoStatus-Wert) */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $progressStep = null;

    /** Erledigte Elemente (Frames, Szenen) im aktuellen Schritt */
    #[ORM\Column(options: ['default' => 0])]
    private int $progressDone = 0;

    /** Bekannte Gesamtzahl der Elemente im aktuellen Schritt */
    #[ORM\Column(options: ['default' => 0])]
    private int $progressTotal = 0;

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

    public function __construct()
    {
        parent::__construct();
        $this->scenes = new ArrayCollection();
        $this->chapters = new ArrayCollection();
        $this->processingSteps = new ArrayCollection();
    }

    public function getType(): string
    {
        return 'video';
    }

    public function getPreviewFile(): ?File
    {
        return $this->thumbnailFile;
    }

    public function getFileHash(): ?string
    {
        return $this->fileHash;
    }

    public function setFileHash(?string $fileHash): static
    {
        $this->fileHash = $fileHash;
        return $this;
    }

    public function getSourceFile(): ?File
    {
        return $this->sourceFile;
    }

    public function setSourceFile(?File $sourceFile): self
    {
        $this->sourceFile = $sourceFile;
        return $this;
    }

    public function getConvertedFile(): ?File
    {
        return $this->convertedFile;
    }

    public function setConvertedFile(?File $convertedFile): self
    {
        $this->convertedFile = $convertedFile;
        return $this;
    }

    public function getThumbnailFile(): ?File
    {
        return $this->thumbnailFile;
    }

    public function setThumbnailFile(?File $thumbnailFile): self
    {
        $this->thumbnailFile = $thumbnailFile;
        return $this;
    }

    /**
     * Gesichter des Videos (Name aus der Zeit vor dem gemeinsamen Medium-Typ, auch in der API so benannt).
     *
     * @return Collection<int, VideoFace>
     */
    #[Groups(['video:detail'])]
    public function getVideoFaces(): Collection
    {
        return $this->faces;
    }

    public function addVideoFace(VideoFace $videoFace): static
    {
        return $this->addFace($videoFace);
    }

    public function removeVideoFace(VideoFace $videoFace): static
    {
        return $this->removeFace($videoFace);
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

    /**
     * IDs der bereits persistierten Szenen, in Szenen-Reihenfolge.
     *
     * @return list<int>
     */
    public function getSceneIds(): array
    {
        $ids = [];
        foreach ($this->scenes as $scene) {
            $id = $scene->getId();
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
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
        return $this->thumbnailFile ? $this->thumbnailFile->getRelativePath() : 'defaults/video-placeholder.jpg';
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

    #[Groups(['video:list', 'video:detail'])]
    public function getSourceFilename(): ?string
    {
        return $this->sourceFile ? basename($this->sourceFile->getRelativePath()) : null;
    }

    /**
     * Ein Element (Frame, Szene) des Schritts ist erledigt, $remaining stehen noch aus.
     * Wechselt der Schritt, beginnt die Zählung von vorn.
     */
    public function recordProgressItem(VideoStatus $step, int $remaining): static
    {
        if ($this->progressStep !== $step->value) {
            $this->progressStep = $step->value;
            $this->progressDone = 0;
            $this->progressTotal = 0;
        }

        $this->progressDone++;
        $this->progressTotal = max($this->progressTotal, $this->progressDone + $remaining);

        return $this;
    }

    public function getTranscript(): ?VideoTranscript
    {
        return $this->transcript;
    }

    public function setTranscript(?VideoTranscript $transcript): static
    {
        $this->transcript = $transcript;

        return $this;
    }

    public function resetProgress(): static
    {
        $this->progressStep = null;
        $this->progressDone = 0;
        $this->progressTotal = 0;

        return $this;
    }

    /**
     * @return array{done: int, total: int}|null Fortschritt, sofern er sich auf diesen Schritt bezieht
     */
    public function getProgressFor(VideoStatus $step): ?array
    {
        if ($this->progressStep !== $step->value || $this->progressTotal <= 0) {
            return null;
        }

        return ['done' => min($this->progressDone, $this->progressTotal), 'total' => $this->progressTotal];
    }
}
