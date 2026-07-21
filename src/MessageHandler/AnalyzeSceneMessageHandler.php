<?php

namespace App\MessageHandler;

use App\Entity\Tag;
use App\Entity\TagCategory;
use App\Message\AnalyzeSceneMessage;
use App\Repository\VideoSceneRepository;
use App\Service\Gemini\GeminiService;
use App\Service\VideoAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class AnalyzeSceneMessageHandler
{
    public function __construct(
        private readonly VideoSceneRepository $videoSceneRepository,
        private readonly GeminiService $geminiService,
        private readonly VideoAnalyzer $videoAnalyzer,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(AnalyzeSceneMessage $message): void
    {
        $scene = $this->videoSceneRepository->find($message->getSceneId());
        if (!$scene) {
            $this->logger->error("Scene not found: " . $message->getSceneId());
            return;
        }

        $video = $scene->getVideo();
        $start = (float)$scene->getStartSeconds();
        $end = (float)$scene->getEndSeconds();
        $duration = $end - $start;

        $timestamps = [];
        if ($duration <= 10) {
            $timestamps[] = $start + ($duration / 2);
        } else if ($duration <= 60) {
            $timestamps[] = $start;
            $timestamps[] = $start + ($duration / 2);
            $timestamps[] = $end;
        } else {
            $count = (int)ceil($duration / 30);
            for ($i = 0; $i <= $count; $i++) {
                $timestamps[] = $start + ($duration * ($i / $count));
            }
        }
        
        $allTags = [];
        
        foreach ($timestamps as $time) {
            $thumbnailPath = $this->videoAnalyzer->extractThumbnail($video, $time);
            if ($thumbnailPath && file_exists($thumbnailPath)) {
                $tagsData = $this->geminiService->analyzeImage($thumbnailPath);
                
                foreach ($tagsData as $category => $tags) {
                    if (!isset($allTags[$category])) {
                        $allTags[$category] = [];
                    }
                    $allTags[$category] = array_unique(array_merge($allTags[$category], $tags));
                }
            }
        }
        
        $currentTags = $scene->getTags()->toArray();
        
        foreach ($allTags as $categoryName => $tags) {
            $category = $this->entityManager->getRepository(TagCategory::class)->findOneBy(['name' => $categoryName]);
            if (!$category) {
                $category = new TagCategory();
                $category->setName($categoryName);
                $this->entityManager->persist($category);
            }
            
            foreach ($tags as $tagName) {
                $tagName = trim($tagName);
                $tag = $this->entityManager->getRepository(Tag::class)->findOneBy(['name' => $tagName, 'category' => $category]);
                if (!$tag) {
                    $tag = new Tag();
                    $tag->setName($tagName);
                    $tag->setCategory($category);
                    $this->entityManager->persist($tag);
                }
                
                $exists = false;
                foreach ($currentTags as $existingTag) {
                    // Check by ID if both have IDs, otherwise check by name + category
                    if (($existingTag->getId() !== null && $tag->getId() !== null && $existingTag->getId() === $tag->getId()) ||
                        ($existingTag->getName() === $tagName && $existingTag->getCategory() === $category)) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $scene->addTag($tag);
                    $currentTags[] = $tag;
                }
            }
        }
        
        $this->entityManager->flush();
        $this->logger->info("Finished tagging scene: " . $scene->getId());
    }
}
