<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\ThumbnailExtraction;

use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Storage\FileManager;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\ThumbnailExtraction\AbstractExtractSceneThumbnailStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\Video\Analyze\SceneThumbnailExtractor;
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

    protected function generateThumbnail(AbstractExtractSceneThumbnailStepMessage $message): ?string
    {
        $scene = $this->videoSceneRepository->find($message->getCurrentSceneId());
        if ($scene === null) {
            return sprintf(
                '[⚠] Cant find scene with id "%s"',
                $message->getCurrentSceneId()
            );
        }

        $video = $scene->getVideo();
        if ($video === null) {
            return sprintf(
                '[⚠] Scene with id "%s" has no video',
                $message->getCurrentSceneId()
            );
        }

        $sourceFileEntity = $video->getSourceFile();
        if ($sourceFileEntity === null) {
            return sprintf(
                '[⚠] Source file entity is missing for video id "%s"',
                $video->getId()
            );
        }

        $videoPath = $this->pathProvider->getStorageRoot() . '/' . $sourceFileEntity->getRelativePath();
        if (!file_exists($videoPath)) {
            return sprintf(
                '[⚠] Source video file not found on disk: "%s"',
                $videoPath
            );
        }

        // Extract thumbnail from the middle of the scene
        $time = ($scene->getStartSeconds() + $scene->getEndSeconds()) / 2;
        $thumbnailFilename = sprintf('scene_%d.jpg', $scene->getId());

        // Der Extractor liefert direkt eine fertige File-Entity zurück
        $thumbnailFile = $this->thumbnailExtractor->extractThumbnail(
            $video,
            $time,
            $thumbnailFilename
        );

        if ($thumbnailFile === null) {
            return sprintf(
                '[⚠] No thumbnail could be generated for scene id "%s"',
                $message->getCurrentSceneId()
            );
        }

        // Als File-Entity an der Scene setzen
        $scene->setThumbnailFile($thumbnailFile);

        // Wenn das Video noch kein Thumbnail hat, dort ebenfalls setzen
        if ($video->getThumbnailFile() === null) {
            $video->setThumbnailFile($thumbnailFile);
        }

        $this->entityManager->flush();

        return null;
    }
}