<?php

namespace App\MessageHandler;

use App\Entity\Tag;
use App\Entity\TagCategory;
use App\Enum\VideoStatus;
use App\Message\AnalyzeSceneMessage;
use App\Message\GenerateChaptersMessage;
use App\Service\WorkflowMachine;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Repository\VideoSceneRepository;
use App\Service\Gemini\GeminiService;
use App\Service\VideoAnalyzer;
use App\Service\VideoProcessingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
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
        #[Target('tagging')] private readonly LoggerInterface $logger,
        private readonly VideoProcessingService $processingService,
        private readonly WorkflowMachine $workflowMachine,
        private readonly MessageBusInterface $messageBus
    ) {
    }

    public function __invoke(AnalyzeSceneMessage $message): void
    {
        try {
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
                $thumbnailRelPath = $this->videoAnalyzer->extractThumbnail($video, $time, sprintf('scene_analysis_%d.jpg', $scene->getId()));
                
                // Wir brauchen den absoluten Pfad zur Analyse
                $basePath = $this->videoAnalyzer->getProjectDir() . '/' . \App\Service\PathConstants::MEDIA_IMAGES;
                $resolvedPath = $basePath . '/' . explode('?', $thumbnailRelPath)[0];
                
                if (file_exists($resolvedPath)) {
                    $tagsData = $this->geminiService->analyzeImage($resolvedPath);
                    
                    $this->logger->info("Gemini analysis result: " . json_encode($tagsData));
                    if ($scene->getTitle() === null && isset($tagsData['Titel'])) {
                        $scene->setTitle($tagsData['Titel']);
                    }

                    $tagsOnly = $tagsData['Tags'] ?? $tagsData;

                    foreach ($tagsOnly as $category => $tags) {
                        if (!is_array($tags)) {
                            continue;
                        }
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

            // Chain: nächste Szene dispatchen oder, wenn alle fertig, GenerateChaptersMessage
            $remaining = $message->getRemainingSceneIds();
            if (!empty($remaining)) {
                $nextId = array_shift($remaining);
                fwrite(STDOUT, "[AnalyzeScene] Szene {$scene->getId()} getaggt – nächste Szene $nextId, " . count($remaining) . " weitere." . PHP_EOL);
                $this->messageBus->dispatch(new AnalyzeSceneMessage($nextId, $remaining));
            } else {
                $this->processingService->finishStep($video, VideoStatus::ANALYZING_SCENES);
                $this->logger->info("All scenes tagged for video " . $video->getId());
                fwrite(STDOUT, "[AnalyzeScene] Alle Szenen getaggt für Video {$video->getId()} – dispatche GenerateChaptersMessage." . PHP_EOL);
                if ($this->workflowMachine->can($video, 'start_chapter_generation')) {
                    $this->workflowMachine->apply($video, 'start_chapter_generation');
                }
                $this->messageBus->dispatch(new GenerateChaptersMessage($video->getId()));
            }

        } catch (\Exception $e) {
            $this->logger->error("Error analyzing scene: " . $e->getMessage());
            throw $e;
        }
    }
}
