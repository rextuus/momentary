<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\Splitting;

use App\Dto\VideoFrame;
use App\Entity\Video;
use App\Entity\VideoScene;
use App\Repository\VideoRepository;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
use App\Service\Video\Analyze\Result\FrameSplittingResult;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;

abstract class AbstractSplitInFramesStepMessageHandler extends AbstractVideoMessageHandler
{
    /** @var list<VideoFrame> */
    protected array $framePathCollection = [];
    protected ?int $currentSceneId = null;
    /** @var list<int> */
    protected array $remainingSceneIds = [];

    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        protected readonly BetterVideoAnalyzer $videoAnalyzer,
        protected readonly StoragePathProvider $pathProvider,
        protected readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    protected function resetState(): void
    {
        $this->framePathCollection = [];
        $this->currentSceneId = null;
        $this->remainingSceneIds = [];
    }

    /**
     * Die Zeitpunkte von extract_frames.py sind schon absolut (Sekunden ab Videobeginn, inklusive Szenenstart).
     * Früher wurde der Szenenstart hier noch einmal addiert: Refinement-Frames ab der zweiten Szene bekamen doppelte
     * Zeitpunkte, oft hinter dem Videoende und ohne Szene.
     */
    /**
     * Die Zeitpunkte von extract_frames.py sind schon absolut (Sekunden ab Videobeginn, inklusive Szenenstart).
     * Früher wurde der Szenenstart hier noch einmal addiert: Refinement-Frames ab der zweiten Szene bekamen doppelte
     * Zeitpunkte, oft hinter dem Videoende und ohne Szene.
     */
    protected function prepareFirstFramesForAnalyzing(FrameSplittingResult $frameSplitResult): void
    {
        $this->framePathCollection = $frameSplitResult->getFrameList();
    }

    protected function addFramesToAnalyzingStep(FrameSplittingResult $frameSplitResult): void
    {
        $this->framePathCollection = array_merge($this->framePathCollection, $frameSplitResult->getFrameList());
    }

    public function decorateNextStepMessage(VideoProcessStepMessageInterface $nextStepMessage): void
    {
        // TODO: Implement decorateNextStepMessage() method.
    }

    protected function resolveVideoPath(Video $video): string
    {
        $fileEntity = $video->getConvertedFile() ?? $video->getSourceFile();

        if ($fileEntity === null) {
            $this->stopProcessing(sprintf(
                'Video file entity for video-entity with id "%s" is missing.',
                $video->getId(),
            ));
            return '';
        }

        $localVideoPath = $this->pathProvider->getAbsolutePath($fileEntity);

        if (!file_exists($localVideoPath)) {
            $errorMsg = sprintf(
                'Video file for video-entity with id "%s" not found at "%s"',
                $video->getId(),
                $localVideoPath,
            );
            $this->stopProcessing($errorMsg);
            return '';
        }

        return $localVideoPath;
    }

    /**
     * Nach dem Teilen einer Szene: noch ausstehend sind die nächste Szene (falls vorhanden) und der Rest.
     */
    protected function recordSceneSplit(): void
    {
        $this->recordItemDone(($this->currentSceneId !== null ? 1 : 0) + \count($this->remainingSceneIds));
    }

    protected function splitSceneIntoFrames(
        VideoScene $scene,
        Video $video,
        string $localVideoPath,
    ): FrameSplittingResult {
        $startTime = $scene->getStartSeconds();
        $endTime = $scene->getEndSeconds();

        // Refinement: höhere Bildrate (null = Refinement-Rate des Videos) und eigener Ordner pro Szene,
        // damit die Frames bereits geteilter Szenen erhalten bleiben, bis sie analysiert sind
        return $this->videoAnalyzer->extractFrames(
            $this->getCurrentMessage()->getVideoId(),
            $localVideoPath,
            null,
            $startTime,
            $endTime - $startTime,
            isRefinement: true,
            subDirectory: 'scene_' . $scene->getId(),
        );
    }
}
