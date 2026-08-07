<?php

namespace App\MessageHandler;

use App\Entity\VideoChapter;
use App\Enum\VideoStatus;
use App\Message\IndexVideoMessage;
use App\Message\GenerateChaptersMessage;
use App\Repository\VideoRepository;
use App\Service\Gemini\GeminiService;
use App\Service\VideoProcessingService;
use App\Service\WorkflowMachine;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly class GenerateChaptersMessageHandler
{
    public function __construct(
        private VideoRepository $videoRepository,
        private GeminiService $geminiService,
        private VideoProcessingService $processingService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private WorkflowMachine $workflowMachine,
        private MessageBusInterface $messageBus
    ) {
    }

    public function __invoke(GenerateChaptersMessage $message): void
    {
        $version = file_get_contents(__DIR__ . '/../../VERSION');
        $this->logger->info("GenerateChaptersMessageHandler called. Version: " . trim($version));
        $video = $this->videoRepository->find($message->getVideoId());
        if (!$video) {
            return;
        }

        // Clear existing chapters
        foreach ($video->getChapters() as $chapter) {
            $this->entityManager->remove($chapter);
        }
        $this->entityManager->flush();
        $this->entityManager->refresh($video);

        $this->processingService->startStep($video, VideoStatus::CHAPTER_GENERATION);

        $scenes = $video->getScenes();
        if ($scenes->isEmpty()) {
            $chapter = new VideoChapter();
            $chapter->setVideo($video);
            $chapter->setTitle("Gesamtes Video");
            $chapter->setStartSeconds(0);
            $chapter->setEndSeconds((int)($video->getDuration() ?? 0));
            $chapter->setDescription("Zusammenfassung des gesamten Videos.");
            $this->entityManager->persist($chapter);
            $this->entityManager->flush();

            $this->processingService->finishStep($video, VideoStatus::CHAPTER_GENERATION);
            $this->logger->info("Finished chapter generation for video " . $video->getId() . " (no scenes found, default chapter)");
            fwrite(STDOUT, "[GenerateChapters] Kapitel generiert für Video {$video->getId()} – dispatche IndexVideoMessage." . PHP_EOL);
            if ($this->workflowMachine->can($video, 'complete')) {
                $this->workflowMachine->apply($video, 'complete');
            }
            $this->messageBus->dispatch(new IndexVideoMessage($video->getId()));
            return;
        }

        $scenesData = [];
        foreach ($scenes as $scene) {
            $scenesData[] = [
                'start' => $scene->getStartSeconds(),
                'end' => $scene->getEndSeconds(),
                'title' => $scene->getTitle(),
                'tags' => array_map(fn($tag) => $tag->getName(), $scene->getTags()->toArray()),
            ];
        }

        try {
            $chaptersData = $this->geminiService->suggestChapters($scenesData);

            foreach ($chaptersData as $data) {
                if (empty($data['title']) || !isset($data['startSeconds']) || !isset($data['endSeconds'])) {
                    continue;
                }
                $chapter = new VideoChapter();
                $chapter->setVideo($video);
                $chapter->setTitle($data['title']);
                $chapter->setStartSeconds((int)$data['startSeconds']);
                $chapter->setEndSeconds((int)$data['endSeconds']);
                $chapter->setDescription($data['description'] ?? null);
                $this->entityManager->persist($chapter);
            }

            $this->entityManager->flush();
            $this->processingService->finishStep($video, VideoStatus::CHAPTER_GENERATION);
            $this->logger->info("Finished chapter generation for video " . $video->getId());
            fwrite(STDOUT, "[GenerateChapters] Kapitel generiert für Video {$video->getId()} – dispatche IndexVideoMessage." . PHP_EOL);
            if ($this->workflowMachine->can($video, 'complete')) {
                $this->workflowMachine->apply($video, 'complete');
            }
            $this->messageBus->dispatch(new IndexVideoMessage($video->getId()));
        } catch (\Exception $e) {
            $this->logger->error("Error generating chapters: " . $e->getMessage());
            $this->processingService->failStep($video, VideoStatus::CHAPTER_GENERATION, $e->getMessage());
        }
    }
}
