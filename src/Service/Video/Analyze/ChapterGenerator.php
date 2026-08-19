<?php

declare(strict_types=1);

namespace App\Service\Video\Analyze;

use App\Entity\Video;
use App\Entity\VideoChapter;
use App\Service\Gemini\GeminiService;
use App\Service\Video\Analyze\Result\ChapterGenerationResult;
use Doctrine\ORM\EntityManagerInterface;

class ChapterGenerator
{
    public function __construct(
        private readonly GeminiService $geminiService,
        private readonly EntityManagerInterface $entityManager
    ) {}

    public function generateChapters(Video $video): ChapterGenerationResult
    {
        try {
            foreach ($video->getChapters() as $chapter) {
                $this->entityManager->remove($chapter);
            }
            $this->entityManager->flush();
            $this->entityManager->refresh($video);

            $scenes = $video->getScenes();

            if ($scenes->isEmpty()) {
                $this->createDefaultChapter($video);
                return new ChapterGenerationResult(true, 1);
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

            $chaptersData = $this->geminiService->suggestChapters($scenesData);

            foreach ($chaptersData as $data) {
                if (empty($data['title']) || !isset($data['startSeconds'], $data['endSeconds'])) {
                    continue;
                }
                $this->createChapter($video, $data);
            }

            $this->entityManager->flush();
            return new ChapterGenerationResult(true, count($chaptersData));

        } catch (\Exception $e) {
            return new ChapterGenerationResult(false, 0, $e->getMessage());
        }
    }

    private function createDefaultChapter(Video $video): void
    {
        $chapter = new VideoChapter();
        $chapter->setVideo($video);
        $chapter->setTitle("Full Video");
        $chapter->setStartSeconds(0);
        $chapter->setEndSeconds((int)($video->getDuration() ?? 0));
        $chapter->setDescription("Summary of the entire video.");
        $this->entityManager->persist($chapter);
        $this->entityManager->flush();
    }

    private function createChapter(Video $video, array $data): void
    {
        $chapter = new VideoChapter();
        $chapter->setVideo($video);
        $chapter->setTitle($data['title']);
        $chapter->setStartSeconds((int)$data['startSeconds']);
        $chapter->setEndSeconds((int)$data['endSeconds']);
        $chapter->setDescription($data['description'] ?? null);
        $this->entityManager->persist($chapter);
    }
}
