<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\ThumbnailExtraction;

use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Analyze\SceneThumbnailExtractor;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\ThumbnailExtraction\AbstractExtractSceneThumbnailStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @author Wolfgang Hinzmann <wolfgang.hinzmann@doccheck.com>
 * @license 2026 DocCheck Community GmbH
 */
abstract class AbstractExtractSceneThumbnailStepMessageHandler extends AbstractVideoMessageHandler
{
    protected ?int $nextSceneId = null;
    /** @var list<int> */
    protected array $remainingSceneIds = [];

    public function __construct(
        VideoRepository $videoRepository,
        VideoProcessMessageDispatcher $dispatcher,
        WorkflowMachine $workflowMachine,
        VideoProcessingService $processingService,
        protected VideoSceneRepository $videoSceneRepository,
        protected SceneThumbnailExtractor $thumbnailExtractor,
        protected StoragePathProvider $pathProvider,
        protected EntityManagerInterface $entityManager,
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    protected function resetState(): void
    {
        $this->nextSceneId = null;
        $this->remainingSceneIds = [];
    }

    protected function generateThumbnail(AbstractExtractSceneThumbnailStepMessage $message): ?string
    {
        $scene = $this->videoSceneRepository->find($message->getCurrentSceneId());
        if ($scene === null) {
            return sprintf('[⚠] Cant find scene with id "%s"', $message->getCurrentSceneId());
        }

        $video = $scene->getVideo();
        if ($video === null) {
            return sprintf('[⚠] Scene with id "%s" has no video', $message->getCurrentSceneId());
        }

        $sourceFileEntity = $video->getSourceFile();
        if ($sourceFileEntity === null) {
            return sprintf('[⚠] Source file entity is missing for video id "%s"', $video->getId());
        }

        $videoPath = $this->pathProvider->getStorageRoot() . '/' . $sourceFileEntity->getRelativePath();
        if (!file_exists($videoPath)) {
            return sprintf('[⚠] Source video file not found on disk: "%s"', $videoPath);
        }

        if ($this->thumbnailExtractor->extractSceneThumbnail($scene) === null) {
            return sprintf('[⚠] No thumbnail could be generated for scene id "%s"', $message->getCurrentSceneId());
        }

        return null;
    }
}
