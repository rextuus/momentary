<?php

namespace App\MessageHandler;

use App\Entity\VideoChapter;
use App\Enum\VideoStatus;
use App\Message\GenerateChaptersMessage;
use App\Repository\VideoRepository;
use App\Service\Gemini\GeminiService;
use App\Service\VideoProcessingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class GenerateChaptersMessageHandler
{
    public function __construct(
        private VideoRepository $videoRepository,
        private GeminiService $geminiService,
        private VideoProcessingService $processingService,
        private EntityManagerInterface $entityManager
    ) {
    }

    public function __invoke(GenerateChaptersMessage $message): void
    {
        $video = $this->videoRepository->find($message->getVideoId());
        if (!$video) {
            return;
        }

        $this->processingService->startStep($video, VideoStatus::CHAPTER_GENERATION);

        $scenes = $video->getScenes();
        $scenesData = [];
        foreach ($scenes as $scene) {
            $scenesData[] = [
                'start' => $scene->getStartSeconds(),
                'end' => $scene->getEndSeconds(),
                'tags' => array_map(fn($tag) => $tag->getName(), $scene->getTags()->toArray()),
            ];
        }

        try {
            $chaptersData = $this->geminiService->suggestChapters($scenesData);

            foreach ($chaptersData as $data) {
                $chapter = new VideoChapter();
                $chapter->setVideo($video);
                $chapter->setTitle($data['title']);
                $chapter->setStartSeconds($data['startSeconds']);
                $chapter->setEndSeconds($data['endSeconds']);
                $chapter->setDescription($data['description'] ?? null);
                $this->entityManager->persist($chapter);
            }

            $this->entityManager->flush();
            $this->processingService->finishStep($video, VideoStatus::CHAPTER_GENERATION);
        } catch (\Exception $e) {
            $this->processingService->failStep($video, VideoStatus::CHAPTER_GENERATION, $e->getMessage());
        }
    }
}
