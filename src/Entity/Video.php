<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

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
    #[Groups(['video:list', 'video:detail'])]
    private ?string $youtubeUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['video:detail'])]
    private ?string $sourceFile = null;

    #[ORM\Column]
    #[Groups(['video:list', 'video:detail'])]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * @var Collection<int, VideoFace>
     */
    #[ORM\OneToMany(targetEntity: VideoFace::class, mappedBy: 'video')]
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
    #[ORM\OneToMany(targetEntity: VideoScene::class, mappedBy: 'video', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sceneNumber' => 'ASC'])]
    private Collection $scenes;

    /**
     * @var Collection<int, VideoChapter>
     */
    #[ORM\OneToMany(targetEntity: VideoChapter::class, mappedBy: 'video', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['startSeconds' => 'ASC'])]
    private Collection $chapters;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['video:list', 'video:detail'])]
    private int $totalFrames = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['video:list', 'video:detail'])]
    private int $processedFrames = 0;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?\DateTimeImmutable $downloadedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?\DateTimeImmutable $convertedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?\DateTimeImmutable $scenesDetectedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?\DateTimeImmutable $framesExtractedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?\DateTimeImmutable $facesAnalyzedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?\DateTimeImmutable $refiningExtractionFinishedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?\DateTimeImmutable $refiningAnalysisFinishedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?\DateTimeImmutable $mergingScenesAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?\DateTimeImmutable $refinedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:list', 'video:detail'])]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:list', 'video:detail'])]
    private ?float $duration = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?int $downloadDuration = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?int $conversionDuration = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?int $sceneDetectionDuration = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?int $frameExtractionDuration = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?int $faceAnalysisDuration = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?int $refiningExtractionDuration = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?int $refiningAnalysisDuration = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?int $mergingScenesDuration = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['video:detail'])]
    private ?string $currentFrameDirectory = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['video:detail'])]
    private ?string $currentRefinementFrameDirectory = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?int $refinementDuration = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?int $estimatedConversionDuration = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?int $estimatedSceneDetectionDuration = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?int $estimatedFrameExtractionDuration = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video:detail'])]
    private ?int $estimatedFaceAnalysisDuration = null;

    #[ORM\Column(length: 511, nullable: true)]
    #[Groups(['video:detail'])]
    private ?string $jellyfinPath = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['video:detail'])]
    private ?string $jellyfinItemId = null;

    public function __construct()
    {
        $this->videoFaces = new ArrayCollection();
        $this->scenes = new ArrayCollection();
        $this->chapters = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getRefiningExtractionFinishedAt(): ?\DateTimeImmutable
    {
        return $this->refiningExtractionFinishedAt;
    }

    public function setRefiningExtractionFinishedAt(?\DateTimeImmutable $refiningExtractionFinishedAt): self
    {
        $this->refiningExtractionFinishedAt = $refiningExtractionFinishedAt;
        return $this;
    }

    public function getRefiningAnalysisFinishedAt(): ?\DateTimeImmutable
    {
        return $this->refiningAnalysisFinishedAt;
    }

    public function setRefiningAnalysisFinishedAt(?\DateTimeImmutable $refiningAnalysisFinishedAt): self
    {
        $this->refiningAnalysisFinishedAt = $refiningAnalysisFinishedAt;
        return $this;
    }

    public function getRefiningExtractionDuration(): ?int
    {
        return $this->refiningExtractionDuration;
    }

    public function setRefiningExtractionDuration(?int $refiningExtractionDuration): self
    {
        $this->refiningExtractionDuration = $refiningExtractionDuration;
        return $this;
    }

    public function getRefiningAnalysisDuration(): ?int
    {
        return $this->refiningAnalysisDuration;
    }

    public function setRefiningAnalysisDuration(?int $refiningAnalysisDuration): self
    {
        $this->refiningAnalysisDuration = $refiningAnalysisDuration;
        return $this;
    }

    public function getMergingScenesAt(): ?\DateTimeImmutable
    {
        return $this->mergingScenesAt;
    }

    public function setMergingScenesAt(?\DateTimeImmutable $mergingScenesAt): self
    {
        $this->mergingScenesAt = $mergingScenesAt;
        return $this;
    }

    public function getMergingScenesDuration(): ?int
    {
        return $this->mergingScenesDuration;
    }

    public function setMergingScenesDuration(?int $mergingScenesDuration): self
    {
        $this->mergingScenesDuration = $mergingScenesDuration;
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

    public function getDownloadedAt(): ?\DateTimeImmutable
    {
        return $this->downloadedAt;
    }

    public function setDownloadedAt(?\DateTimeImmutable $downloadedAt): self
    {
        $this->downloadedAt = $downloadedAt;
        return $this;
    }

    public function getScenesDetectedAt(): ?\DateTimeImmutable
    {
        return $this->scenesDetectedAt;
    }

    public function setScenesDetectedAt(?\DateTimeImmutable $scenesDetectedAt): self
    {
        $this->scenesDetectedAt = $scenesDetectedAt;
        return $this;
    }

    public function getFramesExtractedAt(): ?\DateTimeImmutable
    {
        return $this->framesExtractedAt;
    }

    public function setFramesExtractedAt(?\DateTimeImmutable $framesExtractedAt): self
    {
        $this->framesExtractedAt = $framesExtractedAt;
        return $this;
    }

    public function getFacesAnalyzedAt(): ?\DateTimeImmutable
    {
        return $this->facesAnalyzedAt;
    }

    public function setFacesAnalyzedAt(?\DateTimeImmutable $facesAnalyzedAt): self
    {
        $this->facesAnalyzedAt = $facesAnalyzedAt;
        return $this;
    }

    public function getRefinedAt(): ?\DateTimeImmutable
    {
        return $this->refinedAt;
    }

    public function setRefinedAt(?\DateTimeImmutable $refinedAt): self
    {
        $this->refinedAt = $refinedAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getConvertedAt(): ?\DateTimeImmutable
    {
        return $this->convertedAt;
    }

    public function setConvertedAt(?\DateTimeImmutable $convertedAt): self
    {
        $this->convertedAt = $convertedAt;
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

    public function getDownloadDuration(): ?int
    {
        return $this->downloadDuration;
    }

    public function setDownloadDuration(?int $downloadDuration): self
    {
        $this->downloadDuration = $downloadDuration;
        return $this;
    }

    public function getConversionDuration(): ?int
    {
        return $this->conversionDuration;
    }

    public function setConversionDuration(?int $conversionDuration): self
    {
        $this->conversionDuration = $conversionDuration;
        return $this;
    }

    public function getSceneDetectionDuration(): ?int
    {
        return $this->sceneDetectionDuration;
    }

    public function setSceneDetectionDuration(?int $sceneDetectionDuration): self
    {
        $this->sceneDetectionDuration = $sceneDetectionDuration;
        return $this;
    }

    public function getFrameExtractionDuration(): ?int
    {
        return $this->frameExtractionDuration;
    }

    public function setFrameExtractionDuration(?int $frameExtractionDuration): self
    {
        $this->frameExtractionDuration = $frameExtractionDuration;
        return $this;
    }

    public function getFaceAnalysisDuration(): ?int
    {
        return $this->faceAnalysisDuration;
    }

    public function setFaceAnalysisDuration(?int $faceAnalysisDuration): self
    {
        $this->faceAnalysisDuration = $faceAnalysisDuration;
        return $this;
    }

    public function getRefinementDuration(): ?int
    {
        return $this->refinementDuration;
    }

    public function setRefinementDuration(?int $refinementDuration): self
    {
        $this->refinementDuration = $refinementDuration;
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

    public function getEstimatedConversionDuration(): ?int
    {
        return $this->estimatedConversionDuration;
    }

    public function setEstimatedConversionDuration(?int $estimatedConversionDuration): self
    {
        $this->estimatedConversionDuration = $estimatedConversionDuration;
        return $this;
    }

    public function getEstimatedSceneDetectionDuration(): ?int
    {
        return $this->estimatedSceneDetectionDuration;
    }

    public function setEstimatedSceneDetectionDuration(?int $estimatedSceneDetectionDuration): self
    {
        $this->estimatedSceneDetectionDuration = $estimatedSceneDetectionDuration;
        return $this;
    }

    public function getEstimatedFrameExtractionDuration(): ?int
    {
        return $this->estimatedFrameExtractionDuration;
    }

    public function setEstimatedFrameExtractionDuration(?int $estimatedFrameExtractionDuration): self
    {
        $this->estimatedFrameExtractionDuration = $estimatedFrameExtractionDuration;
        return $this;
    }

    public function getEstimatedFaceAnalysisDuration(): ?int
    {
        return $this->estimatedFaceAnalysisDuration;
    }

    public function setEstimatedFaceAnalysisDuration(?int $estimatedFaceAnalysisDuration): self
    {
        $this->estimatedFaceAnalysisDuration = $estimatedFaceAnalysisDuration;
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
        return $this->convertedVideoPath;
    }
}
