<?php

namespace App\MessageHandler;

use App\Message\TagScenesMessage;
use App\Message\AnalyzeSceneMessage;
use App\Repository\VideoRepository;
use App\Service\WorkflowMachine;
use App\Enum\VideoStatus;
use App\Service\VideoProcessingService;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class TagScenesMessageHandler
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly WorkflowMachine $workflowMachine,
        #[Target('tagging')] private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
        private readonly VideoProcessingService $processingService
    ) {
    }

    public function __invoke(TagScenesMessage $message): void
    {
        fwrite(STDOUT, "[TagScenes] Starte für Video {$message->getVideoId()}." . PHP_EOL);
        $video = $this->videoRepository->find($message->getVideoId());
        if (!$video) {
            fwrite(STDOUT, "[TagScenes] Video {$message->getVideoId()} nicht gefunden." . PHP_EOL);
            return;
        }

        if ($this->workflowMachine->can($video, 'start_tagging')) {
            $this->workflowMachine->apply($video, 'start_tagging');
            $this->processingService->startStep($video, VideoStatus::TAGGING_SCENES);
        }

        $scenes = $video->getScenes();
        $sceneCount = count($scenes);
        $this->logger->info("Dispatching {$sceneCount} scenes for video " . $video->getId());

        $sceneIds = array_values(array_map(fn($s) => $s->getId(), $scenes->toArray()));

        if (empty($sceneIds)) {
            fwrite(STDOUT, "[TagScenes] Keine Szenen für Video {$video->getId()} – überspringe Tagging." . PHP_EOL);
            return;
        }

        $firstId = array_shift($sceneIds);
        $this->messageBus->dispatch(new AnalyzeSceneMessage($firstId, $sceneIds));
        fwrite(STDOUT, "[TagScenes] Starte Chain mit Szene $firstId, " . count($sceneIds) . " weitere für Video {$video->getId()}." . PHP_EOL);
    }
}
