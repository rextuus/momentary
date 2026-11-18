<?php

declare(strict_types=1);

namespace App\Service\Video;

use App\Entity\Video;
use App\Service\Gemini\GeminiService;

class VideoNamingService
{
    public function __construct(
        private readonly GeminiService $geminiService,
    ) {}

    public function suggestName(Video $video): string
    {
        $filename = $video->getSourceFilename();
        // Gespeichert in UTC; Gemini bekommt die Ortszeit
        $createdAt = $video
            ->getCreatedAt()
            ?->setTimezone(new \DateTimeZone('Europe/Berlin'))
            ->format('Y-m-d H:i:s');

        $tags = [];
        foreach ($video->getTags() as $tag) {
            $name = $tag->getName();
            if ($name !== null) {
                $tags[] = $name;
            }
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
