<?php

namespace App\MessageHandler;

use App\Message\TagScenesMessage;
use App\Message\AnalyzeSceneMessage;
use App\Message\GenerateChaptersMessage;
use App\Repository\VideoRepository;
use App\Service\WorkflowMachine;
use App\Enum\VideoStatus;
use App\Service\VideoProcessingService;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly VideoProcessingService $processingService,
        private readonly EntityManagerInterface $entityManager
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

        // Atomarer Guard via DB-Lock: Verhindert doppelte Chain-Starts
        $this->entityManager->getConnection()->beginTransaction();
        try {
            $currentStatus = $this->entityManager->getConnection()->fetchOne(
                "SELECT status FROM video WHERE id = ? FOR UPDATE",
                [$video->getId()]
            );
            if ($currentStatus !== VideoStatus::TAGGING_SCENES->value) {
                $this->entityManager->getConnection()->rollBack();
                fwrite(STDOUT, "[TagScenes] Video {$message->getVideoId()} ist nicht im Status tagging_scenes (ist: $currentStatus) – überspringe." . PHP_EOL);
                return;
            }
            $this->entityManager->getConnection()->commit();
        } catch (\Throwable $e) {
            $this->entityManager->getConnection()->rollBack();
            throw $e;
        }
        $this->entityManager->refresh($video);

        // Workflow-Transition anwenden: tagging_scenes → analyzing_scenes
        if ($this->workflowMachine->can($video, 'start_analyzing_scenes')) {
            $this->workflowMachine->apply($video, 'start_analyzing_scenes');
        }

        $scenes = $video->getScenes();
        $sceneCount = count($scenes);
        $this->logger->info("Dispatching {$sceneCount} scenes for video " . $video->getId());

        $sceneIds = array_values(array_map(fn($s) => $s->getId(), $scenes->toArray()));

        if (empty($sceneIds)) {
            fwrite(STDOUT, "[TagScenes] Keine Szenen für Video {$video->getId()} – überspringe Tagging, starte Kapitelgenerierung." . PHP_EOL);
            if ($this->workflowMachine->can($video, 'start_chapter_generation')) {
                $this->workflowMachine->apply($video, 'start_chapter_generation');
            }
            $this->messageBus->dispatch(new GenerateChaptersMessage($video->getId()));
            return;
        }

        $firstId = array_shift($sceneIds);
        $this->messageBus->dispatch(new AnalyzeSceneMessage($firstId, $sceneIds));
        fwrite(STDOUT, "[TagScenes] Starte Chain mit Szene $firstId, " . count($sceneIds) . " weitere für Video {$video->getId()}." . PHP_EOL);
    }
}
