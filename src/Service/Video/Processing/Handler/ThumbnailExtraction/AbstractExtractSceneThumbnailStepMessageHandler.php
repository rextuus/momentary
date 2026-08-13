<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Handler\ThumbnailExtraction;

use App\Repository\VideoRepository;
use App\Repository\VideoSceneRepository;
use App\Service\Video\Processing\Handler\Abstract\AbstractVideoMessageHandler;
use App\Service\Video\Processing\Message\ThumbnailExtraction\AbstractExtractSceneThumbnailStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use App\Service\VideoAnalyzer;
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
        private readonly VideoSceneRepository $videoSceneRepository,
        private readonly VideoAnalyzer $videoAnalyzer,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct($videoRepository, $dispatcher, $workflowMachine, $processingService);
    }

    protected function generateThumbnail(AbstractExtractSceneThumbnailStepMessage $message): ?string
    {
        $scene = $this->videoSceneRepository->find($message->getCurrentSceneId());
        if ($scene === null){
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

        // Extract thumbnail from the middle of the scene
        $time = ($scene->getStartSeconds() + $scene->getEndSeconds()) / 2;
        $thumbnailPath = $this->videoAnalyzer->extractThumbnail(
            $video,
            $time,
            sprintf('scene_%d.jpg', $scene->getId())
        );

        if ($thumbnailPath === null) {
            return sprintf(
                '[⚠] No thumbnail could be generated for scene id "%s"',
                $message->getCurrentSceneId()
            );
        }

        $scene->setThumbnailUrl($thumbnailPath);

        // set thumbnail of first scene as video thumbnail
        if ($video->getThumbnailPath() === null) {
            $video->setThumbnailPath($thumbnailPath);
        }
        $this->entityManager->flush();

        return null;
    }
}
