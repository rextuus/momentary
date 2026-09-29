<?php

declare(strict_types=1);

namespace App\Service\Video;

use App\Entity\Video;
use App\Service\Gemini\GeminiService;

class VideoNamingService
{
    public function __construct(
        private readonly GeminiService $geminiService
    ) {
    }

    public function suggestName(Video $video): string
    {
        $filename = $video->getSourceFilename();
        $createdAt = $video->getCreatedAt()?->format('Y-m-d H:i:s');

        $tags = [];
        foreach ($video->getTags() as $tag) {
            $tags[] = $tag->getName();
        }
        
        $sceneSummaries = [];
        foreach ($video->getScenes() as $scene) {
            $sceneSummaries[] = [
                'title' => $scene->getTitle(),
            ];
        }
        
        return $this->geminiService->suggestVideoName($tags, $sceneSummaries, $filename ?? 'Unbekannt', $createdAt);
    }
}
